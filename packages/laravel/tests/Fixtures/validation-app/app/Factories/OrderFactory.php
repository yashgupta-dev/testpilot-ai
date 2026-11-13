<?php

declare(strict_types=1);

namespace Shop\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shop\Models\Customer;
use Shop\Models\Order;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'reference' => 'ORD-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(10)),
            'status' => 'draft',
            'quantity' => 1,
        ];
    }
}
