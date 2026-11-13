<?php

declare(strict_types=1);

namespace TestPilot\Storage;

/**
 * Stable fingerprint of a piece of source code. Whitespace-only changes do not change the fingerprint,
 * so reformatting a file does not mark approved tests as stale.
 */
final class SourceFingerprinter
{
    public static function fingerprint(string $source): string
    {
        $normalised = (string) preg_replace('/\s+/', ' ', trim($source));

        return 'sha256:' . hash('sha256', $normalised);
    }
}
