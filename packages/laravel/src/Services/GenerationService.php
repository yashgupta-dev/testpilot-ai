<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use TestPilot\Exceptions\TestPilotException;
use TestPilot\Generation\ScenarioPlan;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Support\TargetHydrator;
use TestPilot\RateLimit\RateLimitTestBuilder;
use TestPilot\Validation\ValidationTestBuilder;
use TestPilot\Verification\GenerationOutcome;
use Throwable;

/**
 * Step 2: turns an approved plan into a verified candidate test that waits in the review queue.
 */
final class GenerationService
{
    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly AuditLogger $audit,
        private readonly FeatureContextBuilder $featureContext,
    ) {
    }

    public function start(Plan $plan): GeneratedTest
    {
        if ($plan->status !== 'approved') {
            throw new TestPilotException('Approve the plan before generating tests.');
        }

        /** @var Target $target */
        $target = $plan->target;

        $test = GeneratedTest::query()->create([
            'target_id' => $target->id,
            'plan_id' => $plan->id,
            'module' => $plan->module ?? 'unit',
            'status' => GeneratedTest::GENERATING,
            'source_fingerprint' => $target->fingerprint,
        ]);
        $target->update(['status' => Target::GENERATING]);

        return $test;
    }

    public function run(GeneratedTest $test): GeneratedTest
    {
        /** @var Plan $plan */
        $plan = $test->plan;
        /** @var Target $target */
        $target = $test->target;

        $core = $this->manager->core();
        $dto = TargetHydrator::hydrate($target);
        $config = $core->resolver->resolve($dto);
        $scenarioPlan = ScenarioPlan::fromArray([
            'target' => $target->key,
            'scenarios' => $plan->scenarios,
            'notes' => (string) $plan->notes,
            'prompt_version' => $plan->prompt_version ?? 'v1',
            'model' => $plan->model,
        ]);

        try {
            $outcome = match ($plan->module) {
                'validation' => $this->validation($core, $dto, $plan),
                'ratelimit' => $this->rateLimit($core, $dto, $plan),
                'feature' => $core->pipeline()->runFeature(
                    $dto,
                    $config,
                    $scenarioPlan,
                    $this->featureContext->describe((array) $plan->context),
                    ['plan-approved-by' => $plan->approved_by],
                ),
                default => $core->pipeline()->run($dto, $config, $scenarioPlan, ['plan-approved-by' => $plan->approved_by]),
            };
        } catch (Throwable $e) {
            $failure = GenerationFailureExplainer::explain($e);
            $test->update([
                'status' => GeneratedTest::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'verification' => $failure === null ? null : ['failure' => $failure],
            ]);
            $target->update(['status' => Target::NEEDS_ATTENTION]);
            $this->audit->log('generation.failed', $test, ['error' => $e->getMessage()]);

            throw $e;
        }

        $status = match ($outcome->status) {
            GenerationOutcome::VERIFIED => GeneratedTest::VERIFIED,
            GenerationOutcome::SUSPECTED_BUG => GeneratedTest::SUSPECTED_BUG,
            GenerationOutcome::UNSAFE => GeneratedTest::UNSAFE,
            default => GeneratedTest::FAILED,
        };

        $test->update([
            'status' => $status,
            'code' => $outcome->code,
            'pending_path' => $outcome->pendingPath,
            'verification' => $outcome->toArray(),
            'mutation_score' => $outcome->mutation?->score,
            'fuzz_seed' => $outcome->fuzzSeed,
            'model' => $outcome->model,
            'repair_attempts' => $outcome->repairAttempts,
        ]);

        $target->update(['status' => $outcome->needsReview() ? Target::AWAITING_APPROVAL : Target::NEEDS_ATTENTION]);
        $this->audit->log('generation.completed', $test, ['status' => $status, 'module' => $test->module, 'repairs' => $outcome->repairAttempts]);

        return $test->refresh();
    }

    private function validation(\TestPilot\TestPilot $core, \TestPilot\DTO\TestTarget $dto, Plan $plan): GenerationOutcome
    {
        $context = (array) $plan->context;
        $scenarios = (array) $plan->scenarios;
        $usesDatabase = collect($context['fields'] ?? [])->flatMap(fn (array $f) => $f['rules'])->contains(fn (array $r): bool => in_array($r['name'], ['unique', 'exists'], true));

        $code = (new ValidationTestBuilder())->build([
            'target' => $dto->key,
            'request_class' => $context['request_class'] ?? null,
            'rules_origin' => (string) ($context['rules_origin'] ?? class_basename((string) $context['request_class']) . '::rules()'),
            'error_mode' => (string) ($context['error_mode'] ?? 'laravel'),
            'error_status' => (int) ($context['error_status'] ?? 422),
            'method' => (string) $context['method'],
            'uri' => (string) $context['uri'],
            'baseline' => (array) $context['baseline'],
            'scenarios' => $scenarios,
            'authenticate' => (bool) ($context['authenticate'] ?? false),
            'user_model' => config('testpilot.validation.user_model', 'App\\Models\\User'),
            'test_case' => config('testpilot.pest.test_case') ?: null,
            'refresh_database' => $usesDatabase || (bool) ($context['authenticate'] ?? false) || self::bindsModels($context) ? (bool) config('testpilot.validation.refresh_database', true) : false,
            'bindings' => $context['bindings'] ?? null,
        ]);

        $runs = array_filter($scenarios, static fn (array $s): bool => (bool) ($s['selected'] ?? false) && ($s['meta']['todo'] ?? null) === null);
        $selected = count($runs);
        $aiCases = array_filter($runs, static fn (array $s): bool => (bool) ($s['meta']['ai'] ?? false));
        // AI readings of custom rules: if one fails, say the AI may be wrong rather than "declared but not enforced".
        $unconfirmed = array_values(array_map(static fn (array $s): string => (string) $s['id'], array_filter($aiCases, static fn (array $s): bool => ! ($s['meta']['verified'] ?? false))));
        $aiModel = $aiCases === [] ? null : (string) ($plan->model ?? 'AI');

        return $core->pipeline()->runPrepared(
            $dto,
            $code,
            'Validation',
            "{$selected} validation cases built from " . ($context['rules_origin'] ?? class_basename((string) $context['request_class']) . '::rules()')
                . ($aiCases === [] ? ' (no AI)' : sprintf(' (%d with AI-proposed values, %d of them checked locally)', count($aiCases), count($aiCases) - count($unconfirmed))),
            ['plan-approved-by' => $plan->approved_by, 'rules' => (string) ($context['rules_origin'] ?? $context['request_class'])],
            $unconfirmed,
            $aiModel,
        );
    }

    private function rateLimit(\TestPilot\TestPilot $core, \TestPilot\DTO\TestTarget $dto, Plan $plan): GenerationOutcome
    {
        $context = (array) $plan->context;
        $scenarios = (array) $plan->scenarios;
        $authenticate = (bool) ($context['authenticate'] ?? false);

        $code = (new RateLimitTestBuilder())->build([
            'target' => $dto->key,
            'method' => (string) $context['method'],
            'uri' => (string) $context['uri'],
            'scenarios' => $scenarios,
            'max_attempts' => (int) $context['max_attempts'],
            'decay_seconds' => (int) $context['decay_seconds'],
            'source' => (string) ($context['source'] ?? ''),
            'authenticate' => $authenticate,
            'user_model' => config('testpilot.validation.user_model', 'App\\Models\\User'),
            'test_case' => config('testpilot.pest.test_case') ?: null,
            'refresh_database' => ($authenticate || self::bindsModels($context)) && (bool) config('testpilot.validation.refresh_database', true),
            'bindings' => $context['bindings'] ?? null,
        ]);

        $selected = count(array_filter($scenarios, static fn (array $s): bool => (bool) ($s['selected'] ?? false) && ($s['meta']['todo'] ?? null) === null));

        return $core->pipeline()->runPrepared(
            $dto,
            $code,
            'RateLimit',
            "{$selected} rate-limit cases for {$context['max_attempts']} requests per {$context['decay_seconds']}s (no AI)",
            ['plan-approved-by' => $plan->approved_by, 'limit' => (string) ($context['source'] ?? '')],
        );
    }

    /** @param array<string, mixed> $context */
    private static function bindsModels(array $context): bool
    {
        return ! empty($context['bindings']) && \TestPilot\Http\RouteBindings::fromArray($context['bindings'])->usesDatabase();
    }
}
