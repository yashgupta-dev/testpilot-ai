<?php

declare(strict_types=1);

namespace TestPilot\Attributes;

use Attribute;

/**
 * Generate seeded random and boundary inputs and assert invariants.
 *
 * TestPilot reads this attribute statically from the AST. The scanner never
 * loads or executes application code, so the package can be a dev dependency.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class Fuzz
{
    public function __construct(
        public bool $enabled = true,
        public ?int $cases = null,
        public ?int $seed = null,
    ) {
    }
}
