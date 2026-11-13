<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Symfony\Component\Process\Process;
use Throwable;

/** Counts recent commits per file. Fails soft: without git, every count is 0. */
final class GitHistory
{
    /** @var array<string, int> */
    private array $cache = [];

    private ?bool $available = null;

    public function __construct(private readonly string $root, private readonly bool $enabled = true, private readonly int $days = 30)
    {
    }

    public function changes(string $relativeFile): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        return $this->cache[$relativeFile] ??= $this->count($relativeFile);
    }

    public function head(): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $process = new Process(['git', 'rev-parse', 'HEAD'], $this->root, null, null, 10);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    public function windowDays(): int
    {
        return $this->days;
    }

    private function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        if (! $this->enabled || ! is_dir($this->root . '/.git')) {
            return $this->available = false;
        }

        try {
            $process = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $this->root, null, null, 10);
            $process->run();

            return $this->available = $process->isSuccessful();
        } catch (Throwable) {
            return $this->available = false;
        }
    }

    private function count(string $file): int
    {
        $process = new Process(['git', 'log', "--since={$this->days} days ago", '--format=%H', '--', $file], $this->root, null, null, 15);
        $process->run();

        return $process->isSuccessful() ? count(array_filter(explode("\n", trim($process->getOutput())))) : 0;
    }
}
