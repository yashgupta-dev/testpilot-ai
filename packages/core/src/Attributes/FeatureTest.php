<?php

declare(strict_types=1);

namespace TestPilot\Attributes;

use Attribute;

/**
 * Generate AI-planned HTTP feature tests for a controller action (Laravel adapter): real requests
 * through the route, assertions on the response, the database and faked side effects.
 *
 * TestPilot reads this attribute statically from the AST. The scanner never
 * loads or executes application code, so the package can be a dev dependency.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class FeatureTest
{
    /**
     * @param list<string> $fakes Side effects to fake in every test: mail, notification, queue, http, event, storage.
     *                            Default: mail, notification, queue and http (stray requests fail the test).
     * @param list<string> $focus Plain-English areas the plan should cover first, e.g. ['coupons', 'stock'].
     */
    public function __construct(
        public bool $enabled = true,
        public ?int $maxScenarios = null,
        public array $fakes = [],
        public array $focus = [],
    ) {
    }
}
