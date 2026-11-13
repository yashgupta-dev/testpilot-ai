<?php

declare(strict_types=1);

namespace TestPilot\Verification;

use TestPilot\Execution\MutationReport;
use TestPilot\Execution\RunResult;

final class GenerationOutcome
{
    /** Verified: passes reliably and is ready for human review. */
    public const VERIFIED = 'verified';
    /** Tests run, but confirmed scenarios fail — the application may have a bug. Needs a human. */
    public const SUSPECTED_BUG = 'suspected_bug';
    /** Could not produce a passing, stable test within the repair budget. */
    public const FAILED = 'failed';
    /** Generated code broke a safety rule and was never executed. */
    public const UNSAFE = 'unsafe';

    /**
     * @param list<StageResult>      $stages
     * @param array<string, string>  $suspectedBugs Scenario id => reason.
     */
    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly ?string $pendingPath,
        public readonly array $stages,
        public readonly int $repairAttempts,
        public readonly ?RunResult $run,
        public readonly ?MutationReport $mutation,
        public readonly ?int $fuzzSeed,
        public readonly ?string $model,
        public readonly array $suspectedBugs = [],
    ) {
    }

    public function needsReview(): bool
    {
        return in_array($this->status, [self::VERIFIED, self::SUSPECTED_BUG], true);
    }

    /** Scenario id => passed|failed|not-run, from the last run. */
    public function scenarioResults(): array
    {
        $results = [];
        foreach ($this->run?->cases ?? [] as $case) {
            $id = $case->scenarioId();
            if ($id !== null) {
                $results[$id] = $case->passed() ? 'passed' : ($case->status === 'skipped' ? 'skipped' : 'failed');
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'pending_path' => $this->pendingPath,
            'stages' => array_map(static fn (StageResult $s): array => $s->toArray(), $this->stages),
            'repair_attempts' => $this->repairAttempts,
            'run' => $this->run?->toArray(),
            'mutation' => $this->mutation?->toArray(),
            'fuzz_seed' => $this->fuzzSeed,
            'model' => $this->model,
            'suspected_bugs' => $this->suspectedBugs,
            'scenario_results' => $this->scenarioResults(),
        ];
    }
}
