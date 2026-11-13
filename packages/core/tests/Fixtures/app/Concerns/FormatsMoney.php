<?php

declare(strict_types=1);

namespace App\Concerns;

trait FormatsMoney
{
    public function formatMoney(float $amount, ?string $symbol = null): string
    {
        return ($symbol ?? '₹') . number_format($amount, 2);
    }
}
