<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;

final class ScanCommand extends Command
{
    protected $signature = 'testpilot:scan {--json : Output the summary as JSON}';

    protected $description = 'Discover TestPilot-annotated code (static analysis only) and update risk scores';

    public function handle(ScanService $scanner): int
    {
        $summary = $scanner->scan();

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Scanned %d files in %.0f ms — %d targets (%d new, %d changed, %d removed, %d tests now stale).',
            $summary['files'], $summary['duration_ms'], $summary['targets'], $summary['added'], $summary['changed'], $summary['removed'], $summary['stale'],
        ));

        $rows = Target::query()->active()->orderByDesc('risk_score')->limit(15)->get()
            ->map(fn (Target $t): array => [$t->risk_score, $t->shortName(), implode(', ', (array) $t->modules), $t->status, "{$t->file}:{$t->line}"])
            ->all();
        $this->table(['Risk', 'Target', 'Modules', 'Status', 'Location'], $rows);

        foreach ($summary['warnings'] as $warning) {
            $this->components->warn($warning);
        }
        foreach ($summary['errors'] as $error) {
            $this->components->error($error);
        }

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
