<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_products_api_returns_stock(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 7,
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.stock', 7);
    }

    public function test_admin_can_create_product_with_stock(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $this->postJson('/api/admin/products', [
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 7,
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock', 7);

        $this->assertDatabaseHas('products', [
            'name' => 'Test Product',
            'stock' => 7,
        ]);
    }

    public function test_admin_can_update_product_stock(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 3,
        ]);

        $this->putJson("/api/admin/products/{$product->id}", [
            'stock' => 9,
        ])
            ->assertOk()
            ->assertJsonPath('data.stock', 9);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 9,
        ]);
    }
}
