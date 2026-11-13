<?php

declare(strict_types=1);

namespace TestPilot\Attributes;

use Attribute;

/**
 * Generate in-process rate-limit tests: allowed requests, 429 + Retry-After, reset and lockout (Laravel adapter).
 *
 * TestPilot reads this attribute statically from the AST. The scanner never
 * loads or executes application code, so the package can be a dev dependency.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RateLimit
{
    public function __construct(
        public bool $enabled = true,
        public ?int $maxAttempts = null,
        public ?int $decaySeconds = null,
        public bool $lockout = false,
    ) {
    }
}
