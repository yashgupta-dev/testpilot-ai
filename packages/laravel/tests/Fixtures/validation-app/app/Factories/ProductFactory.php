<?php

declare(strict_types=1);

namespace Shop\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Shop\Models\Product;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return ['name' => 'Product ' . \Illuminate\Support\Str::random(5), 'price' => 100, 'stock' => 10];
    }
}
