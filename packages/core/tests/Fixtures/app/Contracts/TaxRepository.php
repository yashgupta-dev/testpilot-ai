<?php

declare(strict_types=1);

namespace App\Contracts;

use TestPilot\Attributes\TestPilot;

#[TestPilot]
interface TaxRepository
{
    public function rateFor(string $region): float;
}
