<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use TestPilot\Laravel\Models\AiBatch;
use TestPilot\Laravel\Services\BatchPlanService;

/** Checks a Message Batch and re-schedules itself until it has ended (batches expire after 24 hours). */
final class PollAiBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly AiBatch $batch, public readonly int $polls = 1)
    {
        $this->onConnection(config('testpilot.queue.connection'));
        $this->onQueue(config('testpilot.queue.name', 'testpilot'));
    }

    public function handle(BatchPlanService $service): void
    {
        if ($service->poll($this->batch)) {
            return;
        }

        $interval = max(15, (int) config('testpilot.ai.batch.poll_seconds', 60));
        if ($this->polls * $interval > 25 * 3600) {
            $this->batch->update(['status' => AiBatch::FAILED, 'error' => 'Still not finished after 25 hours; Anthropic expires batches after 24.']);

            return;
        }

        self::dispatch($this->batch, $this->polls + 1)->delay(Carbon::now()->addSeconds($interval));
    }
}
