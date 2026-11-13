<?php

declare(strict_types=1);

namespace TestPilot\Execution;

final class TestCaseResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $class,
        public readonly ?string $file,
        public readonly string $status,
        public readonly float $timeMs,
        public readonly int $assertions,
        public readonly ?string $message = null,
        public readonly ?string $details = null,
    ) {
    }

    /** Scenario id from a test name like "it [S3] throws for negative quantity". */
    public function scenarioId(): ?string
    {
        return preg_match('/\[(S\d{1,3})\]/', $this->name, $m) === 1 ? $m[1] : null;
    }

    public function passed(): bool
    {
        return $this->status === 'passed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'class' => $this->class,
            'file' => $this->file,
            'status' => $this->status,
            'time_ms' => $this->timeMs,
            'assertions' => $this->assertions,
            'message' => $this->message,
            'details' => $this->details,
            'scenario_id' => $this->scenarioId(),
        ];
    }
}
