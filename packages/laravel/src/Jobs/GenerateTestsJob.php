<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Services\GenerationService;

/**
 * Runs on the TestPilot queue so AI calls and test processes never block a web request.
 * Not retried automatically: a failure is recorded and shown in the dashboard instead.
 */
final class GenerateTestsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly GeneratedTest $test)
    {
        $this->onConnection(config('testpilot.queue.connection'));
        $this->onQueue(config('testpilot.queue.name', 'testpilot'));
    }

    public function handle(GenerationService $service): void
    {
        try {
            $service->run($this->test);
        } catch (\TestPilot\Exceptions\AiProviderException|\TestPilot\Exceptions\BudgetExceededException|\TestPilot\Exceptions\AiDisabledException $e) {
            // The test is already marked failed with this message for the dashboard; a provider rate limit or outage
            // is not an application error, so it gets one warning line instead of a failed job with a stack trace.
            \Illuminate\Support\Facades\Log::warning("TestPilot could not generate tests for {$this->test->target?->key}: {$e->getMessage()}");
        }
    }
}
