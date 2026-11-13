<?php

declare(strict_types=1);

namespace Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Shop\Http\Requests\StoreOrderRequest;
use TestPilot\Attributes\TestPilot;
use TestPilot\Attributes\Validation;

#[TestPilot(area: 'Checkout')]
final class OrderController
{
    #[Validation]
    public function store(StoreOrderRequest $request): JsonResponse
    {
        return new JsonResponse(['ok' => true], 201);
    }
}
