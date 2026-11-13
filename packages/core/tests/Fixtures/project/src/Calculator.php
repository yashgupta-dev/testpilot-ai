<?php

declare(strict_types=1);

namespace Demo;

use InvalidArgumentException;
use TestPilot\Attributes\TestPilot;
use TestPilot\Attributes\UnitTest;

#[TestPilot]
final class Calculator
{
    #[UnitTest(maxScenarios: 4)]
    public function divide(int $a, int $b): float
    {
        if ($b === 0) {
            throw new InvalidArgumentException('Division by zero');
        }

        return $a / $b;
    }
}
