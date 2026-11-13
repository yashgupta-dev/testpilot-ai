<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use TestPilot\Attributes\TestPilot;
use TestPilot\Attributes\Validation;

#[TestPilot(area: 'Checkout')]
final class OrderController
{
    #[Validation]
    public function store(StoreOrderRequest $request): array
    {
        return ['ok' => true];
    }
}
