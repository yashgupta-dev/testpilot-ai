<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Run;
use TestPilot\Laravel\Models\RunResult;
use TestPilot\Laravel\Models\Target;
use Throwable;

/**
 * Runs approved tests. Duplicate requests for the same tests and commit reuse the active run.
 */
final class RunService
{
    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly GitHistory $git,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param list<string>|null $targetIds Null runs every approved test. */
    public function queue(string $trigger, ?array $targetIds = null): Run
    {
        $tests = $this->approvedTests($targetIds);
        if ($tests === []) {
            throw new TestPilotException('There are no approved tests to run yet. Approve a generated test first.');
        }

        $sha = $this->git->head();
        $key = hash('sha256', implode(',', array_keys($tests)) . '|' . $sha);

        return DB::transaction(function () use ($trigger, $targetIds, $key, $sha): Run {
            $active = Run::query()->where('idempotency_key', $key)->whereIn('status', [Run::QUEUED, Run::RUNNING])->lockForUpdate()->first();
            if ($active !== null) {
                return $active;
            }

            $run = Run::query()->create([
                'number' => ((int) Run::query()->lockForUpdate()->max('number')) + 1,
                'status' => Run::QUEUED,
                'trigger' => $trigger,
                'idempotency_key' => $key,
                'git_sha' => $sha,
                'target_ids' => $targetIds,
                'started_by' => AuditLogger::currentActor(),
            ]);
            $this->audit->log('run.queued', $run, ['trigger' => $trigger]);

            return $run;
        });
    }

    public function execute(Run $run): Run
    {
        if ($run->status === Run::CANCELLED) {
            return $run;
        }

        $run->update(['status' => Run::RUNNING, 'started_at' => Carbon::now()]);
        $tests = $this->approvedTests($run->target_ids);

        try {
            $result = $this->manager->core()->runner->run(array_values(array_map(static fn (GeneratedTest $t): string => (string) $t->approved_path, $tests)));
        } catch (Throwable $e) {
            $run->update(['status' => Run::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => Carbon::now()]);
            throw $e;
        }

        $byFile = [];
        foreach ($tests as $test) {
            $byFile[basename((string) $test->approved_path)] = $test;
        }

        $targetOutcome = [];
        foreach ($result->cases as $case) {
            $test = $this->matchTest($case->file, $case->class, $byFile);
            RunResult::query()->create([
                'run_id' => $run->id,
                'target_id' => $test?->target_id,
                'generated_test_id' => $test?->id,
                'scenario_id' => $case->scenarioId(),
                'name' => $case->name,
                'status' => $case->status,
                'time_ms' => $case->timeMs,
                'assertions' => $case->assertions,
                'message' => $case->message === null ? null : mb_substr($case->message, 0, 2000),
                'details' => $case->details === null ? null : mb_substr($case->details, 0, 8000),
            ]);

            if ($test !== null) {
                $failed = in_array($case->status, ['failed', 'error'], true);
                $targetOutcome[$test->target_id] = ($targetOutcome[$test->target_id] ?? 'passed') === 'failed' || $failed ? 'failed' : 'passed';
            }
        }

        foreach ($targetOutcome as $targetId => $outcome) {
            Target::query()->whereKey($targetId)->update(['last_result' => $outcome, 'last_run_at' => Carbon::now()]);
        }

        $run->update([
            'status' => $result->passed() ? Run::COMPLETED : Run::FAILED,
            'passed' => $result->count('passed'),
            'failed' => count($result->failed()),
            'skipped' => $result->count('skipped'),
            'assertions' => $result->assertions(),
            'duration_ms' => (int) $result->durationMs,
            'command' => mb_substr($result->command, 0, 250),
            'output' => $result->output,
            'error' => $result->timedOut ? 'The run timed out.' : null,
            'finished_at' => Carbon::now(),
        ]);
        $this->audit->log('run.finished', $run, ['status' => $run->status, 'passed' => $run->passed, 'failed' => $run->failed]);

        return $run->refresh();
    }

    public function cancel(Run $run): Run
    {
        if ($run->status !== Run::QUEUED) {
            throw new TestPilotException('Only queued runs can be cancelled.');
        }

        $run->update(['status' => Run::CANCELLED, 'finished_at' => Carbon::now()]);
        $this->audit->log('run.cancelled', $run);

        return $run;
    }

    /**
     * @param list<string>|null $targetIds
     *
     * @return array<string, GeneratedTest> keyed by id
     */
    private function approvedTests(?array $targetIds): array
    {
        return GeneratedTest::query()
            ->where('status', GeneratedTest::APPROVED)
            ->whereNotNull('approved_path')
            ->when($targetIds !== null, fn ($q) => $q->whereIn('target_id', $targetIds))
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** @param array<string, GeneratedTest> $byFile */
    private function matchTest(?string $file, string $class, array $byFile): ?GeneratedTest
    {
        foreach ([$file, $class] as $hint) {
            if ($hint === null || $hint === '') {
                continue;
            }
            foreach ($byFile as $name => $test) {
                if (str_contains($hint, $name) || str_contains($hint, pathinfo($name, PATHINFO_FILENAME))) {
                    return $test;
                }
            }
        }

        return null;
    }
}
