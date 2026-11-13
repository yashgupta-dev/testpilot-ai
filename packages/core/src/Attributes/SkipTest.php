<?php

declare(strict_types=1);

namespace TestPilot\Attributes;

use Attribute;

/**
 * Exclude this target from TestPilot. An explicit skip wins over inherited enablement.
 *
 * TestPilot reads this attribute statically from the AST. The scanner never
 * loads or executes application code, so the package can be a dev dependency.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class SkipTest
{
    public function __construct(
        public ?string $reason = null,
    ) {
    }
}
