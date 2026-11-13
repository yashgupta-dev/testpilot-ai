<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use TestPilot\Laravel\Models\AiBatch;
use TestPilot\Laravel\Models\AiRequest;

/** Spend, savings and cache effectiveness for the AI usage dashboard. All money in USD. */
final class AiUsageService
{
    public function __construct(private readonly EloquentUsageRecorder $usage)
    {
    }

    /** @return array<string, mixed> */
    public function summary(int $days = 30): array
    {
        $since = Carbon::now()->subDays($days - 1)->startOfDay();
        $month = Carbon::now()->startOfMonth();
        $usd = static fn ($micros): float => round(((int) $micros) / 1_000_000, 4);
        $rows = AiRequest::query()->where('created_at', '>=', $since);

        $totals = (clone $rows)->selectRaw('count(*) as calls, sum(cost_micros) as cost, sum(saved_prompt_cache_micros) as prompt_cache, sum(saved_response_cache_micros) as response_cache, sum(saved_batch_micros) as saved_batch,
            sum(input_tokens) as input, sum(cache_write_tokens) as cache_write, sum(cache_read_tokens) as cache_read, sum(output_tokens) as output,
            sum(case when response_cache_hit then 1 else 0 end) as hits, sum(case when status = \'error\' then 1 else 0 end) as errors')->first();

        $promptInput = (int) $totals->input + (int) $totals->cache_write + (int) $totals->cache_read;
        $budget = (float) config('testpilot.ai.monthly_budget', 0);
        $spent = $this->usage->spentThisMonth();

        $group = fn (string $column): array => (clone $rows)->select($column, DB::raw('count(*) as calls'), DB::raw('sum(cost_micros) as cost'),
            DB::raw('sum(saved_prompt_cache_micros + saved_response_cache_micros + saved_batch_micros) as saved'), DB::raw('sum(cache_read_tokens) as cache_read'),
            DB::raw('sum(input_tokens + cache_write_tokens + cache_read_tokens) as input'))
            ->groupBy($column)->orderByDesc('cost')->get()
            ->map(fn ($r): array => [
                'name' => (string) $r->{$column}, 'calls' => (int) $r->calls, 'cost' => $usd($r->cost), 'saved' => $usd($r->saved),
                'cache_read_share' => (int) $r->input > 0 ? round((int) $r->cache_read / (int) $r->input * 100, 1) : 0.0,
            ])->all();

        $daily = (clone $rows)->select(DB::raw('date(created_at) as day'), DB::raw('sum(cost_micros) as cost'), DB::raw('sum(saved_prompt_cache_micros + saved_response_cache_micros + saved_batch_micros) as saved'))
            ->groupBy('day')->orderBy('day')->get()->keyBy('day');
        $series = [];
        for ($d = $since->copy(); $d->lte(Carbon::now()); $d->addDay()) {
            $row = $daily->get($d->toDateString());
            $series[] = ['day' => $d->toDateString(), 'cost' => $usd($row?->cost ?? 0), 'saved' => $usd($row?->saved ?? 0)];
        }

        return [
            'days' => $days,
            'month' => ['spent' => round($spent, 2), 'budget' => $budget, 'used_ratio' => $budget > 0 ? round($spent / $budget, 3) : null, 'warn_at' => (float) config('testpilot.ai.warn_at', 0.8),
                'month_to_date_calls' => AiRequest::query()->where('created_at', '>=', $month)->count()],
            'totals' => [
                'calls' => (int) $totals->calls, 'errors' => (int) $totals->errors, 'cost' => $usd($totals->cost),
                'saved' => ['prompt_cache' => $usd($totals->prompt_cache), 'response_cache' => $usd($totals->response_cache), 'batch' => $usd($totals->saved_batch),
                    'total' => $usd((int) $totals->prompt_cache + (int) $totals->response_cache + (int) $totals->saved_batch)],
                'tokens' => ['input' => (int) $totals->input, 'cache_write' => (int) $totals->cache_write, 'cache_read' => (int) $totals->cache_read, 'output' => (int) $totals->output],
                'cache_read_share' => $promptInput > 0 ? round((int) $totals->cache_read / $promptInput * 100, 1) : 0.0,
                'response_cache_hits' => (int) $totals->hits,
            ],
            'by_purpose' => $group('purpose'),
            'by_model' => $group('model'),
            'daily' => $series,
            'top_targets' => (clone $rows)->whereNotNull('target_key')->select('target_key', DB::raw('count(*) as calls'), DB::raw('sum(cost_micros) as cost'))
                ->groupBy('target_key')->orderByDesc('cost')->limit(8)->get()
                ->map(fn ($r): array => ['target' => (string) $r->target_key, 'calls' => (int) $r->calls, 'cost' => $usd($r->cost)])->all(),
            'routing' => array_filter((array) config('testpilot.ai.models', []), static fn ($m): bool => is_string($m) && $m !== ''),
            'settings' => [
                'driver' => config('testpilot.ai.driver'), 'default_model' => config('testpilot.ai.model') ?: 'claude-sonnet-5-5',
                'prompt_cache' => config('testpilot.ai.prompt_cache', '5m'), 'response_cache' => (bool) config('testpilot.ai.response_cache.enabled', true),
                'response_cache_days' => (int) config('testpilot.ai.response_cache.ttl_days', 14), 'target_budget' => (float) config('testpilot.ai.target_monthly_budget', 0),
                'batch_available' => strtolower((string) config('testpilot.ai.driver')) === 'anthropic' || strtolower((string) config('testpilot.ai.driver')) === 'claude',
            ],
            'batches' => AiBatch::query()->latest()->limit(10)->get()->map(fn (AiBatch $b): array => [
                'id' => $b->id, 'module' => $b->module, 'status' => $b->status, 'requests' => $b->request_count, 'counts' => $b->counts,
                'created_by' => $b->created_by, 'created_at' => $b->created_at?->toIso8601String(), 'ended_at' => $b->ended_at?->toIso8601String(), 'error' => $b->error,
            ])->all(),
        ];
    }
}
