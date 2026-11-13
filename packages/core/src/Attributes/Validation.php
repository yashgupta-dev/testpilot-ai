<?php

declare(strict_types=1);

namespace TestPilot\Attributes;

use Attribute;

/**
 * Generate rule-by-rule validation tests for the linked FormRequest or route (Laravel adapter).
 *
 * TestPilot reads this attribute statically from the AST. The scanner never
 * loads or executes application code, so the package can be a dev dependency.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Validation
{
    public function __construct(
        public bool $enabled = true,
        /** Status your code returns when validation fails, if it handles failures itself (e.g. 400). Laravel's default is 422. */
        public ?int $errorStatus = null,
        /**
         * AI help for regex and custom rules plus a gap review. Null follows config('testpilot.validation.ai').
         * Known rules never depend on it: their cases always come from rules() alone.
         */
        public ?bool $ai = null,
    ) {
    }
}
