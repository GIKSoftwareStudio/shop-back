<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(), // در صورت نیاز دسته‌بندی می‌سازد
            'name' => fake()->words(3, true), // نام محصول (۳ کلمه‌ای)
            'price' => fake()->randomFloat(2, 10000, 5000000), // قیمت به تومان
            'stock' => fake()->numberBetween(0, 100), // موجودی
        ];
    }
}
