<?php

declare(strict_types=1);

namespace App\Services;

use TestPilot\Attributes\Fuzz;

abstract class BaseService
{
    public function ping(): string
    {
        return 'pong';
    }

    #[Fuzz(cases: 10)]
    public function clamp(int $value, int $min = 0, int $max = 100): int
    {
        return max($min, min($max, $value));
    }

    abstract public function calculateTotal(array $items, float $discount = 0.0, string $region = 'IN'): float;
}
