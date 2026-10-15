<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(), // در صورت نیاز دسته‌بندی می‌سازد
            'name' => fake()->words(3, true), // نام محصول (۳ کلمه‌ای)
            'price' => fake()->randomFloat(2, 10, 1000), // قیمت بین 10 تا 1000
        ];
    }
}
