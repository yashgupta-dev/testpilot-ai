<?php

declare(strict_types=1);

namespace TestPilot\DTO;

final class ScanResult
{
    /**
     * @param list<TestTarget> $targets
     * @param list<string>     $warnings
     * @param list<string>     $errors   Files that could not be parsed.
     */
    public function __construct(
        public readonly array $targets,
        public readonly array $warnings,
        public readonly array $errors,
        public readonly int $filesScanned,
        public readonly float $durationMs,
    ) {
    }

    public function find(string $key): ?TestTarget
    {
        foreach ($this->targets as $target) {
            if ($target->key === $key) {
                return $target;
            }
        }

        return null;
    }
}
