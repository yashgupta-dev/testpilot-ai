<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TestPilot\Laravel\Models\Run;
use TestPilot\Laravel\Services\RunService;

/**
 * Runs on the TestPilot queue so AI calls and test processes never block a web request.
 * Not retried automatically: a failure is recorded and shown in the dashboard instead.
 */
final class RunTestsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly Run $run)
    {
        $this->onConnection(config('testpilot.queue.connection'));
        $this->onQueue(config('testpilot.queue.name', 'testpilot'));
    }

    public function handle(RunService $service): void
    {
        $service->execute($this->run);
    }
}
