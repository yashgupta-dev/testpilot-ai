<?php

declare(strict_types=1);

namespace TestPilot\Execution;

/**
 * Result of `pest --mutate`, parsed best-effort from Pest's console output.
 * When the output format is not recognised, score is null and the raw output is kept for the UI.
 */
final class MutationReport
{
    /** @param list<array{file: string|null, line: int|null, description: string}> $survivors */
    public function __construct(
        public readonly ?float $score,
        public readonly ?int $tested,
        public readonly ?int $caught,
        public readonly ?int $survived,
        public readonly array $survivors,
        public readonly string $output,
        public readonly bool $parsed,
        public readonly ?string $error = null,
    ) {
    }

    public static function fromOutput(string $output): self
    {
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $output);

        $score = preg_match('/Score:\s*([\d.]+)\s*%/i', $plain, $m) === 1 ? (float) $m[1] : null;
        $tested = preg_match('/(\d+)\s+tested/i', $plain, $m) === 1 ? (int) $m[1] : null;
        $caught = preg_match('/(\d+)\s+(?:caught|killed)/i', $plain, $m) === 1 ? (int) $m[1] : null;
        $survived = preg_match('/(\d+)\s+(?:untested|survived|escaped)/i', $plain, $m) === 1 ? (int) $m[1] : null;

        $survivors = [];
        if (preg_match_all('/(?:UNTESTED|SURVIVED)\s+(\S+\.php)\s*>\s*Line\s+(\d+):\s*(.+)/i', $plain, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $survivors[] = ['file' => $match[1], 'line' => (int) $match[2], 'description' => trim($match[3])];
            }
        }

        if ($score === null && $tested !== null && $caught !== null && $tested > 0) {
            $score = round($caught / $tested * 100, 2);
        }

        $error = null;
        if (stripos($plain, 'requires code coverage') !== false) {
            $error = 'Mutation testing needs a code coverage driver. Install PCOV (recommended) or enable Xdebug coverage mode.';
        } elseif ($score === null && preg_match('/(Exception|Error)\b.*$/m', $plain, $m) === 1) {
            $error = trim($m[0]);
        }

        return new self($score, $tested, $caught, $survived, $survivors, mb_substr($plain, -8000), $score !== null, $error);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'tested' => $this->tested,
            'caught' => $this->caught,
            'survived' => $this->survived,
            'survivors' => $this->survivors,
            'parsed' => $this->parsed,
            'error' => $this->error,
        ];
    }
}
