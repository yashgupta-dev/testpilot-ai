<?php

declare(strict_types=1);

namespace TestPilot\Security;

use TestPilot\Exceptions\PolicyViolationException;

/**
 * Keeps every file read and write inside the project root (and, for writes, inside allowed directories).
 * Blocks path traversal such as "../../etc/passwd" and symlink escapes.
 */
final class PathGuard
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new PolicyViolationException("Project root does not exist: {$root}");
        }

        $this->root = rtrim(str_replace('\\', '/', $real), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolve a path (relative to the root, or absolute) and make sure it stays inside the root.
     * The path does not need to exist yet; its closest existing parent is checked instead.
     */
    public function resolveInside(string $path): string
    {
        $candidate = $this->isAbsolute($path) ? $path : $this->root . '/' . $path;
        $normalised = $this->normalise($candidate);

        $existing = $normalised;
        while (! file_exists($existing) && $existing !== dirname($existing)) {
            $existing = dirname($existing);
        }

        $real = realpath($existing);
        $real = $real === false ? $existing : str_replace('\\', '/', $real);

        if (! $this->within($real, $this->root) || ! $this->within($normalised, $this->root)) {
            throw new PolicyViolationException("Path escapes the project root: {$path}");
        }

        return $normalised;
    }

    /**
     * Like resolveInside(), but additionally requires the path to sit inside one of the allowed directories.
     *
     * @param list<string> $allowedDirectories Relative to the root.
     */
    public function resolveWritable(string $path, array $allowedDirectories): string
    {
        $resolved = $this->resolveInside($path);

        foreach ($allowedDirectories as $directory) {
            $allowed = $this->normalise($this->root . '/' . trim($directory, '/'));
            if ($this->within($resolved, $allowed)) {
                return $resolved;
            }
        }

        throw new PolicyViolationException(
            "Writing to {$path} is not allowed. Allowed directories: " . implode(', ', $allowedDirectories),
        );
    }

    public function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);

        if ($absolute === $this->root) {
            return '';
        }

        return str_starts_with($absolute, $this->root . '/')
            ? substr($absolute, strlen($this->root) + 1)
            : $absolute;
    }

    private function within(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, '/') . '/');
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    private function normalise(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('#^([A-Za-z]:)/#', $path, $m) === 1) {
            $prefix = $m[1];
            $path = substr($path, 2);
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return $prefix . '/' . implode('/', $parts);
    }
}
