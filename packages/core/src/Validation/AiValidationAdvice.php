<?php

declare(strict_types=1);

namespace TestPilot\Validation;

/**
 * What the AI contributed to a validation plan, after TestPilot checked it.
 *
 * - values:   input pairs for rules the deterministic engine cannot handle (regex, custom rules).
 *             "verified" ones were checked locally (preg_match) and count as confirmed; the rest are assumptions.
 * - rejected: AI answers TestPilot threw away, with the reason, so a reviewer can see what was discarded.
 * - gaps:     rules the AI thinks are missing or too weak. Findings for a human, never turned into tests.
 */
final class AiValidationAdvice
{
    /**
     * @param array<string, array{question: string, field: string, rule: string, valid: mixed, invalid: mixed, verified: bool, confidence: float, reason: string}> $values keyed by question id
     * @param list<array{question: ?string, reason: string}> $rejected
     * @param list<array{field: string, severity: string, issue: string, suggestion: string, basis_source: string}> $gaps
     * @param list<array{field: string, action: string, value: mixed, expect: string, reason: string}> $cases
     */
    public function __construct(
        public readonly array $values,
        public readonly array $rejected,
        public readonly array $gaps,
        public readonly string $notes,
        public readonly ?string $model,
        public readonly string $promptVersion,
        public readonly int $calls = 1,
        public readonly array $cases = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'values' => array_values($this->values),
            'rejected' => $this->rejected,
            'gaps' => $this->gaps,
            'cases' => $this->cases,
            'notes' => $this->notes,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'calls' => $this->calls,
        ];
    }
}
