<?php

declare(strict_types=1);

namespace TestPilot\Risk;

final class RiskAssessment
{
    /** @param list<array{label: string, detail: string, points: int}> $reasons */
    public function __construct(
        public readonly int $score,
        public readonly string $level,
        public readonly array $reasons,
    ) {
    }

    public static function levelFor(int $score): string
    {
        return match (true) {
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }

    /** @return array{score: int, level: string, reasons: list<array{label: string, detail: string, points: int}>} */
    public function toArray(): array
    {
        return ['score' => $this->score, 'level' => $this->level, 'reasons' => $this->reasons];
    }
}
