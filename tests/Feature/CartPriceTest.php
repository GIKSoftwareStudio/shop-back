<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_prices_api_returns_latest_product_price_and_stock(): void
    {
        $product = Product::create([
            'category_id' => null,
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 5,
        ]);

        $product->update([
            'price' => 250.50,
            'stock' => 8,
        ]);

        $this->postJson('/api/cart/prices', [
            'product_ids' => [$product->id],
        ])
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.price', $product->fresh()->price)
            ->assertJsonPath('products.0.stock', 8)
            ->assertJsonPath('unavailable_product_ids', []);
    }

    public function test_cart_prices_api_reports_missing_and_deleted_products(): void
    {
        $activeProduct = Product::create([
            'category_id' => null,
            'name' => 'Active Product',
            'price' => 100,
            'stock' => 5,
        ]);

        $deletedProduct = Product::create([
            'category_id' => null,
            'name' => 'Deleted Product',
            'price' => 200,
            'stock' => 3,
        ]);

        $deletedProduct->delete();

        $this->postJson('/api/cart/prices', [
            'product_ids' => [
                $activeProduct->id,
                $deletedProduct->id,
                999999,
            ],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $activeProduct->id)
            ->assertJsonPath('unavailable_product_ids', [
                $deletedProduct->id,
                999999,
            ]);
    }

    public function test_cart_prices_api_rejects_duplicate_product_ids(): void
    {
        $product = Product::create([
            'category_id' => null,
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 5,
        ]);

        $this->postJson('/api/cart/prices', [
            'product_ids' => [$product->id, $product->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['product_ids.1']);
    }

    public function test_cart_prices_api_rejects_empty_product_ids(): void
    {
        $this->postJson('/api/cart/prices', [
            'product_ids' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['product_ids']);
    }
}
