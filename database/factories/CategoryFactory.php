<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;


class CategoryFactory extends Factory
{

    public function definition(): array
    {
        return [
            // تولید یک نام دسته‌بندی یکتا یا کلمه تصادفی
            'name' => fake()->unique()->word(),
        ];
    }
}
