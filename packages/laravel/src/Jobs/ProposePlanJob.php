<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\PlanService;

/**
 * Runs on the TestPilot queue so AI calls and test processes never block a web request.
 * Not retried automatically: a failure is recorded and shown in the dashboard instead.
 */
final class ProposePlanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly Target $target, public readonly string $module = 'unit')
    {
        $this->onConnection(config('testpilot.queue.connection'));
        $this->onQueue(config('testpilot.queue.name', 'testpilot'));
    }

    public function handle(PlanService $service): void
    {
        try {
            $service->propose($this->target, $this->module);
        } catch (TestPilotException $e) {
            // An expected "cannot plan this" (no route, rules not readable, AI disabled…): show it on the target
            // instead of failing the job with a stack trace in the error log.
            $alreadyRecorded = Plan::query()->where('target_id', $this->target->id)->where('module', $this->module)
                ->where('status', 'failed')->where('created_at', '>=', now()->subMinute())->exists();
            if (! $alreadyRecorded) {
                Plan::query()->create([
                    'target_id' => $this->target->id,
                    'module' => $this->module,
                    'status' => 'failed',
                    'scenarios' => [],
                    'target_fingerprint' => $this->target->fingerprint,
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                ]);
            }
            $this->target->update(['status' => Target::NEEDS_ATTENTION]);
            Log::warning("TestPilot could not plan {$this->module} tests for {$this->target->key}: {$e->getMessage()}");
        }
    }
}
