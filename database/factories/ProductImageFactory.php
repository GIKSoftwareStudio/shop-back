<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;


class ProductImageFactory extends Factory
{

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'image_url' => fake()->imageUrl(640, 480, 'products', true), // لینک عکس فیک
            'is_primary' => false,
        ];
    }
}
