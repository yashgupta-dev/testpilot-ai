<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use TestPilot\Laravel\Services\DashboardService;

final class ReportCommand extends Command
{
    protected $signature = 'testpilot:report {--format=text : text or json} {--output= : Write the report to this file (relative to base_path)}';

    protected $description = 'Summarise targets, approvals, runs and AI spend';

    public function handle(DashboardService $dashboard): int
    {
        $overview = $dashboard->overview();
        unset($overview['environment']);

        if ($this->option('format') === 'json') {
            $json = (string) json_encode($overview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($path = $this->option('output')) {
                $target = base_path((string) $path);
                abort_unless(str_starts_with(realpath(dirname($target)) ?: '', base_path()), 422, 'Output must be inside the project.');
                file_put_contents($target, $json);
                $this->components->info("Report written to {$path}");
            } else {
                $this->line($json);
            }

            return self::SUCCESS;
        }

        $s = $overview['stats'];
        $this->components->twoColumnDetail('Annotated targets', (string) $s['targets']);
        $this->components->twoColumnDetail('Targets with approved tests', "{$s['with_approved_tests']} ({$s['coverage_of_intent']}%)");
        $this->components->twoColumnDetail('Last pass rate', $s['pass_rate'] === null ? '—' : "{$s['pass_rate']}%");
        $this->components->twoColumnDetail('Average mutation score', $s['mutation_score'] === null ? '—' : "{$s['mutation_score']}%");
        $this->components->twoColumnDetail('Awaiting approval', (string) $s['awaiting_approval']);
        $this->components->twoColumnDetail('Failing targets', (string) $s['failing_targets']);
        $this->components->twoColumnDetail('Stale tests', (string) $s['stale']);
        $this->components->twoColumnDetail('AI spend this month', sprintf('$%.2f of $%.2f', $overview['ai']['spent'], $overview['ai']['budget']));

        return self::SUCCESS;
    }
}
