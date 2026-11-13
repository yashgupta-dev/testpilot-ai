<?php

declare(strict_types=1);

namespace TestPilot\Verification;

final class StageResult
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const WARNING = 'warning';
    public const SKIPPED = 'skipped';

    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $stage,
        public readonly string $status,
        public readonly string $summary,
        public readonly array $data = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['stage' => $this->stage, 'status' => $this->status, 'summary' => $this->summary, 'data' => $this->data];
    }
}
