<?php

declare(strict_types=1);

namespace TestPilot\Attributes;

use Attribute;

/**
 * Opt a class, method or function into TestPilot, optionally tagging its business area and criticality.
 *
 * TestPilot reads this attribute statically from the AST. The scanner never
 * loads or executes application code, so the package can be a dev dependency.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class TestPilot
{
    public function __construct(
        public bool $enabled = true,
        public ?string $area = null,
        public ?string $criticality = null,
    ) {
    }
}
