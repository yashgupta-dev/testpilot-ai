<?php

declare(strict_types=1);

namespace TestPilot\Discovery;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TestPilot\Security\PathGuard;

/**
 * Finds PHP files under the configured paths, honouring exclude patterns.
 */
final class FileFinder
{
    /** Directory names that are never scanned. */
    private const ALWAYS_EXCLUDED = ['vendor', 'node_modules', '.git', 'storage', 'bootstrap/cache'];

    /**
     * @param list<string> $exclude Glob patterns relative to the root (e.g. "app/Legacy/*", "*Test.php").
     */
    public function __construct(
        private readonly PathGuard $guard,
        private readonly array $exclude = [],
    ) {
    }

    /**
     * @param list<string> $paths Paths relative to the project root.
     *
     * @return list<string> Absolute file paths, sorted for deterministic output.
     */
    public function find(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $absolute = $this->guard->resolveInside($path);

            if (is_file($absolute)) {
                if ($this->accepts($absolute)) {
                    $files[$absolute] = true;
                }
                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $directory = new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator(
                $directory,
                function (SplFileInfo $file): bool {
                    if ($file->isDir()) {
                        return ! $this->isExcludedDirectory($file->getPathname());
                    }

                    return $this->accepts($file->getPathname());
                },
            );

            foreach (new RecursiveIteratorIterator($filter) as $file) {
                /** @var SplFileInfo $file */
                if ($file->isFile() && ! $file->isLink()) {
                    $files[$file->getPathname()] = true;
                }
            }
        }

        $list = array_keys($files);
        sort($list);

        return $list;
    }

    private function accepts(string $absolute): bool
    {
        if (! str_ends_with($absolute, '.php')) {
            return false;
        }

        $relative = $this->guard->relative($absolute);

        foreach ($this->exclude as $pattern) {
            if (fnmatch($pattern, $relative) || fnmatch($pattern, basename($relative))) {
                return false;
            }
        }

        return true;
    }

    private function isExcludedDirectory(string $absolute): bool
    {
        $relative = $this->guard->relative($absolute);

        foreach (self::ALWAYS_EXCLUDED as $name) {
            if ($relative === $name || str_ends_with($relative, '/' . $name) || basename($relative) === $name) {
                return true;
            }
        }

        foreach ($this->exclude as $pattern) {
            if (fnmatch(rtrim($pattern, '/*'), $relative)) {
                return true;
            }
        }

        return false;
    }
}
