<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Carbon;
use Throwable;
use TestPilot\Ai\AiRequest;
use TestPilot\Configuration\Module;
use TestPilot\Exceptions\InvalidAiResponseException;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Generation\Scenario;
use TestPilot\Laravel\Jobs\PollAiBatchJob;
use TestPilot\Laravel\Models\AiBatch;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Support\TargetHydrator;

/**
 * Plans many targets in one Anthropic Message Batch: 50% of standard prices (prompt-cache discounts
 * apply on top), results usually within an hour. Every request goes through the gateway's policy,
 * budget, redaction and model routing first, exactly like a live call.
 */
final class BatchPlanService
{
    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly FeatureContextBuilder $feature,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param iterable<Target> $targets
     *
     * @return array{batch: AiBatch, skipped: array<string, string>}
     */
    public function submit(iterable $targets, string $module = 'unit'): array
    {
        if (! in_array($module, ['unit', 'feature'], true)) {
            throw new TestPilotException('Batch planning is for AI-planned modules (unit, feature). Validation and rate-limit plans need no AI.');
        }

        $core = $this->manager->core();
        $client = $core->batches() ?? throw new TestPilotException('Batch planning needs the anthropic driver (Message Batches API). Set TESTPILOT_AI_DRIVER=anthropic.');
        $max = (int) config('testpilot.ai.batch.max_requests', 500);

        $requests = [];
        $plans = [];
        $skipped = [];

        foreach ($targets as $target) {
            if (count($requests) >= $max) {
                $skipped[$target->key] = "Batch limit of {$max} reached; plan it in the next batch.";
                continue;
            }

            try {
                $this->assertPlannable($target, $module);
                $dto = TargetHydrator::hydrate($target);
                $config = $core->resolver->resolve($dto);
                $context = null;
                if ($module === 'feature') {
                    $context = $this->feature->build($target);
                    $request = $core->planner()->buildFeatureRequest($dto, $config, $this->feature->describe($context));
                } else {
                    $request = $core->planner()->buildRequest($dto, $config);
                }
                $prepared = $core->ai()->prepare($request, $target->key);
            } catch (Throwable $e) {
                $skipped[$target->key] = $e->getMessage();
                continue;
            }

            $plan = Plan::query()->create([
                'target_id' => $target->id,
                'module' => $module,
                'status' => 'queued',
                'scenarios' => [],
                'context' => [...($context ?? []), 'batch' => ['modules' => $prepared->meta['modules'], 'max' => $prepared->meta['max'], 'model' => $prepared->model]],
                'model' => $prepared->model,
                'prompt_version' => ($module === 'feature' ? 'feature@' : '') . $prepared->promptVersion,
                'target_fingerprint' => $target->fingerprint,
            ]);
            $requests[$plan->id] = $prepared;
            $plans[] = $plan;
        }

        if ($requests === []) {
            throw new TestPilotException('Nothing to batch: ' . (implode(' ', array_slice(array_values($skipped), 0, 3)) ?: 'no targets selected.'));
        }

        $batch = AiBatch::query()->create([
            'module' => $module,
            'status' => AiBatch::IN_PROGRESS,
            'request_count' => count($requests),
            'created_by' => AuditLogger::currentActor(),
        ]);
        Plan::query()->whereIn('id', array_keys($requests))->update(['ai_batch_id' => $batch->id]);

        try {
            $created = $client->create($requests);
        } catch (Throwable $e) {
            $batch->update(['status' => AiBatch::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            Plan::query()->whereIn('id', array_keys($requests))->update(['status' => 'failed', 'error' => 'Batch submission failed: ' . mb_substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }

        $batch->update(['provider_batch_id' => $created['id']]);
        Target::query()->whereIn('id', array_map(static fn (Plan $p) => $p->target_id, $plans))->update(['status' => Target::PLANNING]);
        $this->audit->log('ai.batch.submitted', $batch, ['module' => $module, 'requests' => count($requests), 'skipped' => count($skipped)]);

        if (! $this->queueIsSync()) {
            PollAiBatchJob::dispatch($batch)->delay(Carbon::now()->addSeconds((int) config('testpilot.ai.batch.poll_seconds', 60)));
        }

        return ['batch' => $batch->refresh(), 'skipped' => $skipped];
    }

    /** Checks the batch once; when it has ended, turns every result into a proposed (or failed) plan. Returns true when done. */
    public function poll(AiBatch $batch): bool
    {
        if ($batch->status !== AiBatch::IN_PROGRESS) {
            return true;
        }

        $core = $this->manager->core();
        $client = $core->batches() ?? throw new TestPilotException('Batch polling needs the anthropic driver.');
        $status = $client->retrieve((string) $batch->provider_batch_id);
        $batch->update(['counts' => $status['counts']]);

        if ($status['status'] !== 'ended') {
            return false;
        }

        $results = $client->results((string) $batch->provider_batch_id);

        foreach ($batch->plans()->where('status', 'queued')->with('target')->get() as $plan) {
            $this->apply($plan, $results[$plan->id] ?? ['type' => 'expired', 'response' => null, 'error' => 'No result returned for this request.']);
        }

        $batch->update(['status' => AiBatch::ENDED, 'ended_at' => Carbon::now()]);
        $this->audit->log('ai.batch.ended', $batch, ['counts' => $status['counts']]);

        return true;
    }

    /** @param array{type: string, response: ?\TestPilot\Ai\AiResponse, error: ?string} $outcome */
    private function apply(Plan $plan, array $outcome): void
    {
        $core = $this->manager->core();
        /** @var Target $target */
        $target = $plan->target;
        $meta = (array) ($plan->context['batch'] ?? []);
        $request = new AiRequest('plan', '', [], promptVersion: \TestPilot\Ai\PromptRegistry::VERSION, meta: [...$meta, 'target' => $target->key]);
        $core->ai()->recordExternal($request->withModel($meta['model'] ?? null), $outcome['response'], $target->key, $outcome['type'] === 'succeeded' ? null : $outcome['error']);

        if ($outcome['type'] !== 'succeeded' || $outcome['response'] === null) {
            $this->fail($plan, $target, "Batch request {$outcome['type']}: " . ($outcome['error'] ?? 'no details'));

            return;
        }

        try {
            $result = $core->planner()->fromResponse($request, TargetHydrator::hydrate($target), $outcome['response']);
        } catch (InvalidAiResponseException $e) {
            $this->fail($plan, $target, $e->getMessage() . '. Plan this target again to retry live.');

            return;
        }

        Plan::query()->where('target_id', $target->id)->where('module', $plan->module)->where('status', 'proposed')->update(['status' => 'superseded']);
        $plan->update([
            'status' => 'proposed',
            'scenarios' => array_map(static fn (Scenario $s): array => $s->toArray(), $result->scenarios),
            'notes' => $result->notes,
            'model' => $result->model,
        ]);
        $target->update(['status' => Target::PLAN_READY]);
    }

    private function fail(Plan $plan, Target $target, string $error): void
    {
        $plan->update(['status' => 'failed', 'error' => mb_substr($error, 0, 2000)]);
        $target->update(['status' => Target::NEEDS_ATTENTION]);
    }

    private function assertPlannable(Target $target, string $module): void
    {
        if (! $target->enabled) {
            throw new TestPilotException('skipped (#[SkipTest])');
        }
        $modules = (array) $target->modules;
        $ok = $module === 'feature'
            ? in_array(Module::Feature->value, $modules, true)
            : array_intersect($modules, [Module::Unit->value, Module::Fuzz->value]) !== [];
        if (! $ok) {
            throw new TestPilotException($module === 'feature' ? 'no #[FeatureTest]' : 'no #[UnitTest] or #[Fuzz]');
        }
    }

    private function queueIsSync(): bool
    {
        return (config('testpilot.queue.connection') ?? config('queue.default')) === 'sync';
    }
}
