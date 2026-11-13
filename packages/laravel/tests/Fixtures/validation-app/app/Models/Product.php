<?php

declare(strict_types=1);

namespace Shop\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Shop\Factories\ProductFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'price', 'stock'];

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
