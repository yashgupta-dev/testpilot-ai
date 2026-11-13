<?php

declare(strict_types=1);

namespace TestPilot\DTO;

final class TargetLocation
{
    public function __construct(
        /** Path relative to the project root, using forward slashes. */
        public readonly string $file,
        public readonly int $startLine,
        public readonly int $endLine,
    ) {
    }

    public function __toString(): string
    {
        return $this->file . ':' . $this->startLine;
    }
}
