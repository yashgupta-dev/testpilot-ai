<?php

declare(strict_types=1);

namespace App\Support;

use TestPilot\Attributes as TP;

#[TP\TestPilot]
#[TP\Fuzz(cases: 20)]
function money_round(float $amount, ?int $precision = 2): float
{
    return round($amount, $precision ?? 2);
}

#[TP\Bogus]
function unknown_attribute(): void
{
}

function not_annotated(): void
{
}
