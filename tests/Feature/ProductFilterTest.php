<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\WithFaker;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_api_can_filter_by_category_id(): void
    {
        $category1 = Category::create([
            'name' => 'Category One',
        ]);

        $category2 = Category::create([
            'name' => 'Category Two',
        ]);

        $product1 = Product::create([
            'category_id' => $category1->id,
            'name' => 'Product One',
            'price' => 100,
            'stock' => 5,
        ]);

        Product::create([
            'category_id' => $category2->id,
            'name' => 'Product Two',
            'price' => 200,
            'stock' => 10,
        ]);

        $this->getJson('/api/products?category_id=' . $category1->id)
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $product1->id)
            ->assertJsonPath('products.0.category.id', $category1->id);
    }
}
