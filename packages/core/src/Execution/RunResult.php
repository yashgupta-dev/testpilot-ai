<?php

declare(strict_types=1);

namespace TestPilot\Execution;

final class RunResult
{
    /** @param list<TestCaseResult> $cases */
    public function __construct(
        public readonly int $exitCode,
        public readonly array $cases,
        public readonly string $output,
        public readonly float $durationMs,
        public readonly string $command,
        public readonly bool $timedOut = false,
    ) {
    }

    public function passed(): bool
    {
        if ($this->timedOut || $this->exitCode !== 0 || $this->failed() !== []) {
            return false;
        }

        $passed = array_filter($this->cases, static fn (TestCaseResult $case): bool => $case->passed());

        if ($passed === []) {
            return false;
        }

        foreach ($passed as $case) {
            if ($case->assertions === 0) {
                return false;
            }
        }

        return true;
    }

    /** @return list<TestCaseResult> */
    public function failed(): array
    {
        return array_values(array_filter($this->cases, static fn (TestCaseResult $c): bool => in_array($c->status, ['failed', 'error'], true)));
    }

    public function count(string $status): int
    {
        return count(array_filter($this->cases, static fn (TestCaseResult $c): bool => $c->status === $status));
    }

    public function assertions(): int
    {
        return array_sum(array_map(static fn (TestCaseResult $c): int => $c->assertions, $this->cases));
    }

    /** A compact, truncated failure report suitable for the repair prompt and the UI. */
    public function failureSummary(int $maxChars = 6000): string
    {
        if ($this->timedOut) {
            return "The test run timed out.\n" . substr($this->output, -2000);
        }

        $parts = [];
        foreach ($this->failed() as $case) {
            $parts[] = "✗ {$case->name}\n" . trim((string) ($case->details ?? $case->message));
        }

        if ($parts === []) {
            $parts[] = substr($this->output, -3000);
        }

        return mb_substr(implode("\n\n", $parts), 0, $maxChars);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'passed' => $this->passed(),
            'counts' => [
                'passed' => $this->count('passed'),
                'failed' => $this->count('failed') + $this->count('error'),
                'skipped' => $this->count('skipped'),
            ],
            'assertions' => $this->assertions(),
            'duration_ms' => $this->durationMs,
            'command' => $this->command,
            'timed_out' => $this->timedOut,
            'cases' => array_map(static fn (TestCaseResult $c): array => $c->toArray(), $this->cases),
        ];
    }
}
