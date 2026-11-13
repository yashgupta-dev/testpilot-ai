<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Services\ApprovalService;

final class ReviewCommand extends Command
{
    protected $signature = 'testpilot:review
        {--approve= : Id of a verified test to approve}
        {--reject= : Id of a test to reject}
        {--reason= : Reason for rejecting}';

    protected $description = 'List generated tests waiting for review, or approve/reject one';

    public function handle(ApprovalService $approvals): int
    {
        try {
            if ($id = $this->option('approve')) {
                $test = $approvals->approve(GeneratedTest::query()->findOrFail($id));
                $this->components->info("Approved → {$test->approved_path}");

                return self::SUCCESS;
            }

            if ($id = $this->option('reject')) {
                $reason = (string) ($this->option('reason') ?: $this->ask('Why are you rejecting this test?'));
                $approvals->reject(GeneratedTest::query()->findOrFail($id), $reason);
                $this->components->info('Rejected.');

                return self::SUCCESS;
            }
        } catch (TestPilotException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $pending = GeneratedTest::query()->with('target')->whereIn('status', [GeneratedTest::VERIFIED, GeneratedTest::SUSPECTED_BUG])->latest()->get();

        if ($pending->isEmpty()) {
            $this->components->info('Nothing is waiting for review.');

            return self::SUCCESS;
        }

        $this->table(['Id', 'Target', 'Status', 'Mutation', 'Repairs', 'Pending file'], $pending->map(static fn (GeneratedTest $t): array => [
            $t->id, $t->target?->shortName(), $t->status, $t->mutation_score === null ? '—' : $t->mutation_score . '%', $t->repair_attempts, $t->pending_path,
        ])->all());

        return self::SUCCESS;
    }
}
