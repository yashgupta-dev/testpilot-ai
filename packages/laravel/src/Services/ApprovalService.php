<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Support\Carbon;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Support\TargetHydrator;

/**
 * Step 3: a human approves or rejects a verified test. Only approval moves a file into tests/.
 */
final class ApprovalService
{
    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly AuditLogger $audit,
    ) {
    }

    public function approve(GeneratedTest $test): GeneratedTest
    {
        if (! $test->awaitingReview()) {
            throw new TestPilotException("Only verified tests can be approved (this one is {$test->status}).");
        }

        /** @var Target $target */
        $target = $test->target;
        if ($target->fingerprint !== $test->source_fingerprint) {
            throw new TestPilotException('The source code changed after this test was generated. Regenerate it before approving.');
        }

        $writer = $this->manager->core()->writer;
        $dto = TargetHydrator::hydrate($target);
        $actor = AuditLogger::currentActor();

        $path = $writer->approve((string) $test->pending_path, $writer->approvedPath($dto, PlanService::directory($test->module)), [
            'approved-by' => $actor,
            'approved-at' => Carbon::now()->toIso8601String(),
        ]);

        GeneratedTest::query()
            ->where('target_id', $target->id)
            ->where('module', $test->module ?? 'unit')
            ->whereIn('status', [GeneratedTest::APPROVED, GeneratedTest::STALE])
            ->whereKeyNot($test->getKey())
            ->update(['status' => GeneratedTest::STALE]);

        $test->update([
            'status' => GeneratedTest::APPROVED,
            'approved_path' => $path,
            'reviewed_by' => $actor,
            'reviewed_at' => Carbon::now(),
        ]);
        $target->update(['status' => Target::APPROVED]);
        $this->audit->log('test.approved', $test, ['target' => $target->key, 'module' => $test->module, 'path' => $path]);

        return $test->refresh();
    }

    public function reject(GeneratedTest $test, string $reason): GeneratedTest
    {
        if (! in_array($test->status, [GeneratedTest::VERIFIED, GeneratedTest::SUSPECTED_BUG, GeneratedTest::FAILED, GeneratedTest::UNSAFE], true)) {
            throw new TestPilotException("This test cannot be rejected (status {$test->status}).");
        }

        if ($test->pending_path !== null) {
            $this->manager->core()->writer->discard((string) $test->pending_path);
        }

        $test->update([
            'status' => GeneratedTest::REJECTED,
            'rejection_reason' => mb_substr(trim($reason), 0, 2000),
            'reviewed_by' => AuditLogger::currentActor(),
            'reviewed_at' => Carbon::now(),
        ]);

        /** @var Target $target */
        $target = $test->target;
        $target->update(['status' => Target::NOT_PLANNED]);
        $this->audit->log('test.rejected', $test, ['target' => $target->key, 'reason' => $reason]);

        return $test->refresh();
    }
}
