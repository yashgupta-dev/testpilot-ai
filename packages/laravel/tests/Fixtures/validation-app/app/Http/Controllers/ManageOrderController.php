<?php

declare(strict_types=1);

namespace Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Shop\Http\Requests\UpdateOrderRequest;
use Shop\Models\Coupon;
use Shop\Models\Customer;
use Shop\Models\Order;
use TestPilot\Attributes\RateLimit;
use TestPilot\Attributes\TestPilot;
use TestPilot\Attributes\Validation;

/** Routes with URL parameters: implicit model binding, a scoped custom key, a scalar id and a model without a factory. */
#[TestPilot(area: 'Orders')]
final class ManageOrderController
{
    /** PUT /api/orders/{order} — a missing record would 404 before validation runs. */
    #[Validation]
    #[RateLimit]
    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $order->update($request->validated());

        return new JsonResponse(['id' => $order->id]);
    }

    /** PATCH /api/customers/{customer}/orders/{order:reference} — the order must belong to the customer. */
    #[Validation]
    public function updateForCustomer(UpdateOrderRequest $request, Customer $customer, Order $order): JsonResponse
    {
        return new JsonResponse(['id' => $order->id, 'customer' => $customer->id]);
    }

    /** PUT /api/legacy-orders/{id} — no model binding, the id is a plain value. */
    #[Validation]
    public function updateLegacy(UpdateOrderRequest $request, int $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }

    /** DELETE /api/coupons/{coupon} — Coupon has no factory. */
    #[RateLimit]
    public function destroyCoupon(Coupon $coupon): JsonResponse
    {
        return new JsonResponse(null, 204);
    }
}
