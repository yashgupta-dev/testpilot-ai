<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Fired once per month when AI spend crosses testpilot.ai.warn_at of the monthly budget. Listen to notify Slack/email. */
final class AiBudgetWarning
{
    use Dispatchable;

    public function __construct(
        public readonly float $spent,
        public readonly float $budget,
        public readonly float $ratio,
    ) {
    }
}
