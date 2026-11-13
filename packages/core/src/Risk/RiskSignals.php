<?php

declare(strict_types=1);

namespace TestPilot\Risk;

final class RiskSignals
{
    public function __construct(
        public readonly bool $hasApprovedTests = false,
        public readonly int $recentChanges = 0,
        public readonly int $changeWindowDays = 30,
        public readonly bool $isPublicRoute = false,
        public readonly bool $lastRunFailed = false,
    ) {
    }
}
