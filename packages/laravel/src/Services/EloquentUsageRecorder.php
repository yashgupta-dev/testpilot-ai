<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\Pricing;
use TestPilot\Ai\UsageRecorder;
use TestPilot\Exceptions\BudgetExceededException;
use TestPilot\Laravel\Events\AiBudgetWarning;
use TestPilot\Laravel\Models\AiRequest as AiRequestModel;

/**
 * Stores every AI call (tokens incl. prompt-cache writes/reads, cost, savings, latency) and enforces
 * the monthly and per-target budgets. Prompts and source code are NOT stored, only metadata.
 */
final class EloquentUsageRecorder implements UsageRecorder
{
    private readonly Pricing $pricing;

    /** @param array<string, array<string, mixed>> $pricing USD per 1M tokens, merged over Pricing::DEFAULTS */
    public function __construct(
        private readonly float $monthlyBudget,
        array $pricing = [],
        private readonly float $targetMonthlyBudget = 0.0,
        private readonly float $warnAt = 0.8,
        /** True when the API key is on a free tier (Groq, Gemini AI Studio, …): calls are recorded at $0. */
        private readonly bool $freeTier = false,
    ) {
        $this->pricing = new Pricing([...Pricing::DEFAULTS, ...$pricing]);
    }

    public function assertWithinBudget(string $purpose, ?string $targetKey = null): void
    {
        if ($this->monthlyBudget > 0) {
            $spent = $this->spentThisMonth();
            if ($spent >= $this->monthlyBudget) {
                throw new BudgetExceededException(sprintf(
                    'Monthly AI budget reached ($%.2f of $%.2f). Raise TESTPILOT_AI_MONTHLY_BUDGET or wait until next month.',
                    $spent,
                    $this->monthlyBudget,
                ));
            }
        }

        if ($this->targetMonthlyBudget > 0 && $targetKey !== null) {
            $spent = (int) AiRequestModel::query()->where('target_key', $targetKey)->where('created_at', '>=', Carbon::now()->startOfMonth())->sum('cost_micros') / 1_000_000;
            if ($spent >= $this->targetMonthlyBudget) {
                throw new BudgetExceededException(sprintf(
                    'This target used its monthly AI budget ($%.2f of $%.2f). Raise TESTPILOT_AI_TARGET_BUDGET if it really needs more planning.',
                    $spent,
                    $this->targetMonthlyBudget,
                ));
            }
        }
    }

    public function record(AiRequest $request, ?AiResponse $response, string $provider, string $model, ?string $targetKey, ?string $error): void
    {
        // Local models, ":free" models (OpenRouter) and free-tier keys cost nothing, so they never use up the budget.
        $free = $this->freeTier || $provider === 'ollama' || str_ends_with((string) ($response?->model ?: $model), ':free');
        $cost = $response === null || $free ? ['cost' => 0.0, 'without_cache' => 0.0, 'saved' => 0.0] : $this->pricing->cost($response);
        $micros = static fn (float $usd): int => (int) round($usd * 1_000_000);
        $hit = $response?->fromResponseCache ?? false;

        AiRequestModel::query()->create([
            'purpose' => $request->purpose,
            'provider' => $provider,
            'model' => $response?->model ?: $model,
            'prompt_version' => $request->promptVersion,
            'target_key' => $targetKey,
            'input_tokens' => $hit ? 0 : ($response?->inputTokens ?? 0),
            'output_tokens' => $hit ? 0 : ($response?->outputTokens ?? 0),
            'cache_write_tokens' => $hit ? 0 : ($response === null ? 0 : $response->cacheWrite5mTokens + $response->cacheWrite1hTokens),
            'cache_read_tokens' => $hit ? 0 : ($response?->cacheReadTokens ?? 0),
            'cost_micros' => $micros($cost['cost']),
            'saved_prompt_cache_micros' => $hit ? 0 : $micros($cost['saved']),
            'saved_response_cache_micros' => $hit ? $micros($cost['saved']) : 0,
            // Batch usage is billed at 50%: the other half is the saving.
            'saved_batch_micros' => ($response?->batch ?? false) ? $micros($cost['cost']) : 0,
            'latency_ms' => $response?->latencyMs ?? 0,
            'status' => $error !== null ? 'error' : ($hit ? 'cache_hit' : 'ok'),
            'response_cache_hit' => $hit,
            'batch' => $response?->batch ?? false,
            'attempt' => isset($request->meta['attempt']) ? (int) $request->meta['attempt'] : null,
            'error' => $error === null ? null : mb_substr($error, 0, 1000),
        ]);

        $this->warnIfNeeded();
    }

    public function spentThisMonth(): float
    {
        return (int) AiRequestModel::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('cost_micros') / 1_000_000;
    }

    public function pricing(): Pricing
    {
        return $this->pricing;
    }

    private function warnIfNeeded(): void
    {
        if ($this->monthlyBudget <= 0 || $this->warnAt <= 0) {
            return;
        }

        $spent = $this->spentThisMonth();
        $ratio = $spent / $this->monthlyBudget;
        $key = 'testpilot:ai-budget-warned:' . Carbon::now()->format('Y-m');

        if ($ratio >= $this->warnAt && Cache::add($key, true, Carbon::now()->endOfMonth())) {
            AiBudgetWarning::dispatch($spent, $this->monthlyBudget, $ratio);
        }
    }
}
