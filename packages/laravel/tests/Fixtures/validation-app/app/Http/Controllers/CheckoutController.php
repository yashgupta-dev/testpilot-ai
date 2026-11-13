<?php

declare(strict_types=1);

namespace Shop\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Shop\Jobs\SendInvoice;
use Shop\Models\Customer;
use Shop\Models\Order;
use Shop\Models\Product;
use TestPilot\Attributes\FeatureTest;
use TestPilot\Attributes\TestPilot;

/** A "fat controller": validation, pricing, stock and persistence all inside the action. */
#[TestPilot(area: 'Checkout', criticality: 'high')]
final class CheckoutController
{
    #[FeatureTest(focus: ['coupons', 'stock'])]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1|max:20',
            'coupon' => 'nullable|string',
        ]);

        $product = Product::findOrFail($data['product_id']);
        if ($product->stock < $data['quantity']) {
            return response()->json(['message' => 'Out of stock'], 409);
        }

        $total = $product->price * $data['quantity'];
        if (config('shop.coupons', true) && ($data['coupon'] ?? null) === 'SAVE10') {
            $total = round($total * 0.9, 2);
        }

        $customer = Customer::query()->firstOrCreate(['name' => 'Guest']);
        $order = Order::create([
            'customer_id' => $customer->id,
            'reference' => 'ORD-' . Str::upper(Str::random(10)),
            'status' => 'placed',
            'quantity' => $data['quantity'],
            'total' => $total,
            'coupon' => $data['coupon'] ?? null,
        ]);

        $product->decrement('stock', $data['quantity']);
        SendInvoice::dispatch($order);

        return response()->json(['id' => $order->id, 'total' => $total], 201);
    }

    /** Not routed: feature tests must refuse it with a clear message. */
    #[FeatureTest]
    public function preview(Request $request): JsonResponse
    {
        return response()->json([]);
    }
}
