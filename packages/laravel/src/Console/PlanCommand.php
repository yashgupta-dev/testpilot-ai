<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use Throwable;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\BatchPlanService;
use TestPilot\Laravel\Services\PlanService;

final class PlanCommand extends Command
{
    use ResolvesTargets;

    protected $signature = 'testpilot:plan
        {targets?* : Target ids, keys or short names (Class::method)}
        {--all : Every target that has the module and no plan yet}
        {--module=unit : unit or feature (AI-planned modules)}
        {--batch : Use the Message Batches API: 50% cheaper, results usually within an hour}';

    protected $description = 'Ask the AI for test plans, one by one or as a discounted batch';

    public function handle(PlanService $plans, BatchPlanService $batches): int
    {
        $module = (string) $this->option('module');
        $targets = $this->targets($module);
        if ($targets === []) {
            $this->components->warn('No targets to plan. Pass target names or --all.');

            return 2;
        }

        if ($this->option('batch')) {
            try {
                $result = $batches->submit($targets, $module);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return 1;
            }
            $this->components->info("Batch {$result['batch']->id} submitted with {$result['batch']->request_count} target(s) at 50% of the standard price.");
            foreach ($result['skipped'] as $key => $why) {
                $this->line("  <fg=yellow>skipped</> {$key}: {$why}");
            }
            $this->line('  Plans appear in the dashboard when the batch ends. Without a queue worker, run: php artisan testpilot:batches --poll');

            return 0;
        }

        $failed = 0;
        foreach ($targets as $target) {
            $this->components->task("Planning {$target->shortName()}", function () use ($plans, $target, $module, &$failed): bool {
                try {
                    $plans->propose($target, $module);

                    return true;
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->line("  <fg=red>{$e->getMessage()}</>");

                    return false;
                }
            });
        }

        return $failed === 0 ? 0 : 1;
    }

    /** @return list<Target> */
    private function targets(string $module): array
    {
        if ($this->option('all')) {
            return Target::query()->active()->where('enabled', true)->whereIn('status', [Target::NOT_PLANNED, Target::STALE])->get()
                ->filter(fn (Target $t): bool => in_array($module === 'feature' ? 'feature' : 'unit', \TestPilot\Laravel\Services\PlanService::plannableModules($t), true))
                ->values()->all();
        }

        $found = [];
        foreach ((array) $this->argument('targets') as $needle) {
            $target = $this->findTarget((string) $needle);
            if ($target === null) {
                $this->components->warn("Target not found: {$needle}");
                continue;
            }
            $found[] = $target;
        }

        return $found;
    }
}
