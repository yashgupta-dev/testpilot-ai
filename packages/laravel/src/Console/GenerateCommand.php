<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Services\GenerationService;
use TestPilot\Laravel\Services\PlanService;

/**
 * Plan → (approve plan) → generate → verify, from the terminal. The resulting test still waits for
 * approval (testpilot:review or the dashboard) before it is written into tests/.
 */
final class GenerateCommand extends Command
{
    use ResolvesTargets;

    protected $signature = 'testpilot:generate
        {target : Target id, full key or short name (e.g. "InvoiceService::calculateTotal")}
        {--module=unit : unit (AI-planned), feature (AI-planned HTTP tests), validation (from the rules) or ratelimit (from throttle middleware / #[RateLimit])}
        {--accept-plan : Approve the proposed scenarios without asking (assumptions with low confidence stay unselected)}';

    protected $description = 'Plan scenarios with AI, then generate and verify a Pest test for one target';

    public function handle(PlanService $plans, GenerationService $generation): int
    {
        $target = $this->findTarget((string) $this->argument('target'));
        if ($target === null) {
            $this->components->error('Target not found. Run testpilot:scan first, then use the name shown there.');

            return self::FAILURE;
        }

        try {
            $module = (string) $this->option('module');
            $this->components->task("Planning {$module} scenarios for {$target->shortName()}", function () use ($plans, $target, $module, &$plan): void {
                $plan = $plans->propose($target, $module);
            });
            if ($plan->notes) {
                $this->line('  ' . $plan->notes);
            }

            $this->table(['', 'Id', 'Scenario', 'Basis', 'Confidence'], array_map(static fn (array $s): array => [
                $s['selected'] ? '✓' : ' ', $s['id'], $s['name'] . (($s['meta']['todo'] ?? null) !== null ? ' (needs a human)' : ''), $s['basis'], number_format((float) $s['confidence'], 2),
            ], (array) $plan->scenarios));

            $selected = array_values(array_map(static fn (array $s): string => $s['id'], array_filter((array) $plan->scenarios, static fn (array $s): bool => (bool) $s['selected'])));

            if (! $this->option('accept-plan')) {
                $answer = $this->ask('Scenario ids to keep (comma separated, Enter = the ✓ ones)', implode(',', $selected));
                $selected = array_values(array_filter(array_map('trim', explode(',', (string) $answer))));
            }

            $approved = $plans->approve($plan, $selected);
            $test = $generation->start($approved);

            $this->components->task('Generating, running, repairing and verifying', function () use ($generation, &$test): void {
                $test = $generation->run($test);
            });
        } catch (TestPilotException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ((array) ($test->verification['stages'] ?? []) as $stage) {
            $this->line(sprintf('  %-10s %-8s %s', $stage['stage'], $stage['status'], $stage['summary']));
        }

        $message = match ($test->status) {
            GeneratedTest::VERIFIED => "Verified. Review it with: php artisan testpilot:review --approve={$test->id}",
            GeneratedTest::SUSPECTED_BUG => 'Tests run, but a confirmed scenario fails — this may be a real bug. Review before approving.',
            GeneratedTest::UNSAFE => 'The generated code broke a safety rule and was never executed.',
            default => 'Could not produce a passing, stable test. See the dashboard for the failure output.',
        };
        $test->status === GeneratedTest::VERIFIED ? $this->components->info($message) : $this->components->warn($message);

        return in_array($test->status, [GeneratedTest::VERIFIED, GeneratedTest::SUSPECTED_BUG], true) ? self::SUCCESS : self::FAILURE;
    }
}
