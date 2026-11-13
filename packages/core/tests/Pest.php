<?php

declare(strict_types=1);

use TestPilot\Discovery\ProjectScanner;
use TestPilot\DTO\ScanResult;
use TestPilot\Security\PathGuard;

function fixtures(string $path = ''): string
{
    return __DIR__ . '/Fixtures' . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function scanFixtures(array $paths = ['app']): ScanResult
{
    return (new ProjectScanner(new PathGuard(fixtures())))->scan($paths);
}

/** Creates an empty temporary directory that is removed at shutdown. */
function temporaryDirectory(): string
{
    $dir = sys_get_temp_dir() . '/testpilot-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    register_shutdown_function(static function () use ($dir): void {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    });

    return $dir;
}

const INVOICE = 'App\Services\InvoiceService::calculateTotal';
