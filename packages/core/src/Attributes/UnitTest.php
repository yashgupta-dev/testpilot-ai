<?php

declare(strict_types=1);

namespace TestPilot\Attributes;

use Attribute;

/**
 * Generate AI-planned Pest unit tests (positive, negative and edge cases).
 *
 * TestPilot reads this attribute statically from the AST. The scanner never
 * loads or executes application code, so the package can be a dev dependency.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class UnitTest
{
    public function __construct(
        public bool $enabled = true,
        public ?int $maxScenarios = null,
        /** @var list<string> */
        public array $focus = [],
    ) {
    }
}
