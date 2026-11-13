<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use TestPilot\Exceptions\PolicyViolationException;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\Run;
use TestPilot\Laravel\Services\RunService;

/**
 * Exit codes (for CI): 0 = all approved tests pass, 1 = failures, 2 = nothing to run / configuration problem, 3 = policy violation.
 */
final class RunCommand extends Command
{
    use ResolvesTargets;

    protected $signature = 'testpilot:run
        {--target=* : Only run tests for these targets}
        {--ci : Non-interactive CI mode (never generates or approves anything)}';

    protected $description = 'Run approved TestPilot tests and record the results';

    public function handle(RunService $runs): int
    {
        $ids = null;
        foreach ((array) $this->option('target') as $needle) {
            $target = $this->findTarget((string) $needle);
            if ($target === null) {
                $this->components->error("Target not found: {$needle}");

                return 2;
            }
            $ids[] = $target->id;
        }

        try {
            $run = $runs->execute($runs->queue($this->option('ci') ? 'ci' : 'cli', $ids));
        } catch (PolicyViolationException $e) {
            $this->components->error($e->getMessage());

            return 3;
        } catch (TestPilotException $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        foreach ($run->results as $result) {
            $this->line(sprintf('  %s %s', $result->status === 'passed' ? '<fg=green>✓</>' : ($result->status === 'skipped' ? '<fg=yellow>-</>' : '<fg=red>✗</>'), $result->name));
        }

        $this->newLine();
        $summary = "Run #{$run->number}: {$run->passed} passed, {$run->failed} failed, {$run->skipped} skipped in {$run->duration_ms} ms";
        $run->status === Run::COMPLETED ? $this->components->info($summary) : $this->components->error($summary);

        return $run->status === Run::COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
