<?php

declare(strict_types=1);

namespace Shop\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shop\Models\Customer;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return ['name' => 'Customer ' . \Illuminate\Support\Str::random(6)];
    }
}
