<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Carbon;
use TestPilot\Configuration\Module;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Generation\Scenario;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Support\TargetHydrator;
use Throwable;

/**
 * Step 1 of the human-in-the-loop flow: the AI proposes scenarios, a developer edits/approves them.
 */
final class PlanService
{
    public const MODULES = ['unit', 'validation', 'ratelimit', 'feature'];

    /** Folder name under pending/ and tests/TestPilot/Generated/ for each module. */
    public const DIRECTORIES = ['unit' => 'Unit', 'validation' => 'Validation', 'ratelimit' => 'RateLimit', 'feature' => 'Feature'];

    /** Modules that send real HTTP requests, so the target must be reachable through a route. */
    public const ROUTED_MODULES = ['validation', 'ratelimit', 'feature'];

    /** Modules whose expectations are derived from code (validation may add checked AI values; see ValidationPlanBuilder). */
    public const DETERMINISTIC = ['validation', 'ratelimit'];

    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly AuditLogger $audit,
        private readonly ValidationPlanBuilder $validation,
        private readonly RateLimitPlanBuilder $rateLimit,
        private readonly FeatureContextBuilder $feature,
    ) {
    }

    /** Modules this target can be planned for in this release. */
    public static function plannableModules(Target $target): array
    {
        $modules = (array) $target->modules;
        $available = [];
        if (array_intersect($modules, [Module::Unit->value, Module::Fuzz->value]) !== []) {
            $available[] = 'unit';
        }
        if (in_array(Module::Validation->value, $modules, true)) {
            $available[] = 'validation';
        }
        if (in_array(Module::RateLimit->value, $modules, true)) {
            $available[] = 'ratelimit';
        }
        if (in_array(Module::Feature->value, $modules, true)) {
            $available[] = 'feature';
        }

        return $available;
    }

    public static function directory(?string $module): string
    {
        return self::DIRECTORIES[$module ?? 'unit'] ?? 'Unit';
    }

    public function propose(Target $target, string $module = 'unit'): Plan
    {
        if ($module === 'validation') {
            return $this->proposeDeterministic($target, 'validation', Module::Validation, 'Validation', fn () => $this->validation->build($target));
        }
        if ($module === 'feature') {
            return $this->proposeFeature($target);
        }
        if ($module === 'ratelimit') {
            return $this->proposeDeterministic($target, 'ratelimit', Module::RateLimit, 'RateLimit', fn () => $this->rateLimit->build($target));
        }

        $this->assertPlannable($target);

        $core = $this->manager->core();
        $dto = TargetHydrator::hydrate($target);
        $config = $core->resolver->resolve($dto);

        $target->update(['status' => Target::PLANNING]);

        try {
            // Asking again for an existing plan means "give me a different answer": bypass the response cache.
            $fresh = Plan::query()->where('target_id', $target->id)->where('module', 'unit')->exists();
            $plan = $core->planner()->plan($dto, $config, $fresh);
        } catch (Throwable $e) {
            $target->update(['status' => Target::NEEDS_ATTENTION]);
            $failure = GenerationFailureExplainer::explain($e);
            Plan::query()->create([
                'target_id' => $target->id,
                'module' => 'unit',
                'status' => 'failed',
                'scenarios' => [],
                'context' => $failure === null ? null : ['failure' => $failure],
                'target_fingerprint' => $target->fingerprint,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            throw $e;
        }

        Plan::query()->where('target_id', $target->id)->where('module', 'unit')->where('status', 'proposed')->update(['status' => 'superseded']);

        $row = Plan::query()->create([
            'target_id' => $target->id,
            'module' => 'unit',
            'status' => 'proposed',
            'scenarios' => array_map(static fn (Scenario $s): array => $s->toArray(), $plan->scenarios),
            'notes' => $plan->notes,
            'model' => $plan->model,
            'prompt_version' => $plan->promptVersion,
            'target_fingerprint' => $target->fingerprint,
        ]);

        $target->update(['status' => Target::PLAN_READY]);
        $this->audit->log('plan.proposed', $row, ['target' => $target->key, 'scenarios' => count($plan->scenarios)]);

        return $row;
    }

    /**
     * Approve a plan with the selected scenario ids, optional edits and optional extra scenarios written by a human.
     *
     * @param list<string>                     $selectedIds
     * @param list<array<string, mixed>>       $edits      Partial scenario arrays keyed by "id".
     * @param list<string>                     $additions  Plain-English scenarios added by the reviewer.
     */
    public function approve(Plan $plan, array $selectedIds, array $edits = [], array $additions = []): Plan
    {
        if ($plan->status !== 'proposed') {
            throw new TestPilotException("Only proposed plans can be approved (this one is {$plan->status}).");
        }

        $byId = [];
        foreach ($edits as $edit) {
            if (isset($edit['id'])) {
                $byId[(string) $edit['id']] = $edit;
            }
        }

        $scenarios = [];
        foreach ((array) $plan->scenarios as $scenario) {
            $editable = array_intersect_key($byId[$scenario['id']] ?? [], array_flip(['name', 'expected', 'inputs']));
            $scenarios[] = [...$scenario, ...$editable, 'selected' => in_array($scenario['id'], $selectedIds, true)];
        }

        if (in_array($plan->module, self::DETERMINISTIC, true) && array_filter($additions, static fn ($a) => trim((string) $a) !== '') !== []) {
            throw new TestPilotException('Plain-English additions are only supported for AI-planned unit tests. ' . ($plan->module === 'ratelimit' ? 'Rate-limit cases come from the declared limit.' : 'Validation cases come from rules().'));
        }

        $next = count($scenarios) + 1;
        foreach ($additions as $text) {
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $scenarios[] = [
                'id' => 'S' . $next++,
                'name' => mb_substr($text, 0, 120),
                'type' => 'edge',
                'module' => $plan->module === 'feature' ? 'feature' : 'unit',
                'description' => $text,
                'inputs' => '',
                'expected' => $text,
                'basis' => 'confirmed',
                'basis_source' => 'Added by reviewer ' . AuditLogger::currentActor(),
                'confidence' => 1.0,
                'priority' => 'high',
                'selected' => true,
            ];
        }

        if (array_filter($scenarios, static fn (array $s): bool => $s['selected']) === []) {
            throw new TestPilotException('Select at least one scenario before approving the plan.');
        }

        $plan->update([
            'status' => 'approved',
            'scenarios' => $scenarios,
            'approved_by' => AuditLogger::currentActor(),
            'approved_at' => Carbon::now(),
        ]);

        $this->audit->log('plan.approved', $plan, ['selected' => count(array_filter($scenarios, static fn ($s) => $s['selected'])), 'added' => count($additions)]);

        return $plan;
    }

    private function proposeFeature(Target $target): Plan
    {
        if (! $target->enabled) {
            throw new TestPilotException("{$target->key} is skipped (#[SkipTest]).");
        }
        if (! in_array(Module::Feature->value, (array) $target->modules, true)) {
            throw new TestPilotException("Add #[FeatureTest] to {$target->shortName()} to generate HTTP feature tests.");
        }

        $context = $this->feature->build($target);
        $core = $this->manager->core();
        $dto = TargetHydrator::hydrate($target);
        $config = $core->resolver->resolve($dto);

        $target->update(['status' => Target::PLANNING]);

        try {
            $fresh = Plan::query()->where('target_id', $target->id)->where('module', 'feature')->exists();
            $plan = $core->planner()->planFeature($dto, $config, $this->feature->describe($context), $fresh);
        } catch (Throwable $e) {
            $target->update(['status' => Target::NEEDS_ATTENTION]);
            $failure = GenerationFailureExplainer::explain($e);
            Plan::query()->create([
                'target_id' => $target->id,
                'module' => 'feature',
                'status' => 'failed',
                'scenarios' => [],
                'context' => $failure === null ? $context : [...$context, 'failure' => $failure],
                'target_fingerprint' => $target->fingerprint,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            throw $e;
        }

        Plan::query()->where('target_id', $target->id)->where('module', 'feature')->where('status', 'proposed')->update(['status' => 'superseded']);

        $row = Plan::query()->create([
            'target_id' => $target->id,
            'module' => 'feature',
            'status' => 'proposed',
            'scenarios' => array_map(static fn (Scenario $s): array => $s->toArray(), $plan->scenarios),
            'context' => $context,
            'notes' => $plan->notes,
            'model' => $plan->model,
            'prompt_version' => 'feature@' . $plan->promptVersion,
            'target_fingerprint' => $target->fingerprint,
        ]);

        $target->update(['status' => Target::PLAN_READY]);
        $this->audit->log('plan.proposed', $row, ['target' => $target->key, 'module' => 'feature', 'scenarios' => count($plan->scenarios)]);

        return $row;
    }

    /** @param \Closure(): array{scenarios: list<array<string, mixed>>, context: array<string, mixed>, notes: string, model?: ?string, prompt_version?: string} $build */
    private function proposeDeterministic(Target $target, string $module, Module $required, string $attribute, \Closure $build): Plan
    {
        if (! $target->enabled) {
            throw new TestPilotException("{$target->key} is skipped (#[SkipTest]).");
        }
        if (! in_array($required->value, (array) $target->modules, true)) {
            throw new TestPilotException("Add #[{$attribute}] to {$target->shortName()} to generate " . ($module === 'ratelimit' ? 'rate-limit' : $module) . ' tests.');
        }

        $built = $build();
        if ($module === 'validation') {
            $built = $this->omitApprovedValidationDuplicates($target, $built);
        }

        Plan::query()->where('target_id', $target->id)->where('module', $module)->where('status', 'proposed')->update(['status' => 'superseded']);

        $row = Plan::query()->create([
            'target_id' => $target->id,
            'module' => $module,
            'status' => 'proposed',
            'scenarios' => $built['scenarios'],
            'context' => $built['context'],
            'notes' => $built['notes'],
            'model' => $built['model'] ?? null,
            'prompt_version' => $built['prompt_version'] ?? "{$module}@v1",
            'target_fingerprint' => $target->fingerprint,
        ]);

        $target->update(['status' => Target::PLAN_READY]);
        $this->audit->log('plan.proposed', $row, ['target' => $target->key, 'module' => $module, 'scenarios' => count($built['scenarios'])]);

        return $row;
    }

    /**
     * @param array{scenarios: list<array<string, mixed>>, context: array<string, mixed>, notes: string, model?: ?string, prompt_version?: string} $built
     * @return array{scenarios: list<array<string, mixed>>, context: array<string, mixed>, notes: string, model?: ?string, prompt_version?: string}
     */
    private function omitApprovedValidationDuplicates(Target $target, array $built): array
    {
        $approvedKeys = [];
        $approvedTests = GeneratedTest::query()
            ->where('target_id', $target->id)
            ->where('module', 'validation')
            ->whereIn('status', [GeneratedTest::APPROVED, GeneratedTest::STALE])
            ->where('source_fingerprint', $target->fingerprint)
            ->with('plan')
            ->get();

        foreach ($approvedTests as $test) {
            $plan = $test->plan;
            if ($plan === null || $plan->module !== 'validation') {
                continue;
            }

            foreach ((array) $plan->scenarios as $scenario) {
                $meta = (array) ($scenario['meta'] ?? []);
                if (! ($scenario['selected'] ?? false) || ($meta['todo'] ?? null) !== null) {
                    continue;
                }
                $key = $this->validationScenarioKey($scenario, (array) $plan->context);
                if ($key !== null) {
                    $approvedKeys[$key] = true;
                }
            }
        }

        if ($approvedKeys === []) {
            return $built;
        }

        $omitted = 0;
        $scenarios = [];
        foreach ($built['scenarios'] as $scenario) {
            $key = $this->validationScenarioKey($scenario, $built['context']);
            if ($key !== null && isset($approvedKeys[$key])) {
                $omitted++;
                continue;
            }
            $scenarios[] = $scenario;
        }

        if ($omitted === 0) {
            return $built;
        }

        $selectedRemaining = count(array_filter($scenarios, static fn (array $scenario): bool => (bool) ($scenario['selected'] ?? false)));
        if ($selectedRemaining === 0) {
            throw new TestPilotException("All {$omitted} generated validation cases are already covered by approved tests for this unchanged target.");
        }

        $built['scenarios'] = $scenarios;
        $built['context']['approved_duplicates_omitted'] = $omitted;
        $built['notes'] .= " {$omitted} duplicate case(s) omitted because they are already covered by an approved test.";

        return $built;
    }

    /** @param array<string, mixed> $scenario */
    private function validationScenarioKey(array $scenario, array $context): ?string
    {
        $meta = (array) ($scenario['meta'] ?? []);
        if (! isset($meta['action']) || ! array_key_exists('expect', $meta)) {
            return null;
        }

        $identity = [
            'method' => $context['method'] ?? null,
            'uri' => $context['uri'] ?? null,
            'field' => $meta['field'] ?? null,
            'target_field' => $meta['target_field'] ?? null,
            'action' => $meta['action'],
            'value' => $meta['value'] ?? null,
            'payload' => ($meta['field'] ?? null) === null && $meta['action'] === 'none' ? ($context['baseline'] ?? null) : null,
            'expect' => $meta['expect'],
            'also_set' => $meta['also_set'] ?? [],
        ];

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function assertPlannable(Target $target): void
    {
        if (! $target->enabled) {
            throw new TestPilotException("{$target->key} is skipped (#[SkipTest]).");
        }

        $modules = (array) $target->modules;
        $aiModules = array_intersect($modules, [Module::Unit->value, Module::Fuzz->value]);

        if ($aiModules === []) {
            $others = array_values(array_intersect(['validation', 'ratelimit'], self::plannableModules($target)));
            $hint = $others === [] ? '' : ' Plan ' . implode(' or ', array_map(static fn (string $m): string => "\"{$m}\"", $others)) . ' tests instead.';
            throw new TestPilotException('This target has no unit or fuzz module; add #[UnitTest] to plan unit tests with AI.' . $hint);
        }
    }
}
