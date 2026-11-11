<?php

namespace Tests\Feature;

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

    public function test_products_api_can_search_by_name(): void
    {
        $category = Category::create(['name' => 'Mobile']);

        $matched = Product::create([
            'category_id' => $category->id,
            'name' => 'Samsung Galaxy',
            'price' => 10000000,
            'stock' => 5,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Apple iPhone',
            'price' => 20000000,
            'stock' => 3,
        ]);

        $this->getJson('/api/products?search=Samsung')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $matched->id);
    }

    public function test_products_api_can_filter_by_price_range(): void
    {
        $category = Category::create(['name' => 'Mobile']);

        $matched = Product::create([
            'category_id' => $category->id,
            'name' => 'Product One',
            'price' => 5000000,
            'stock' => 5,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Product Two',
            'price' => 15000000,
            'stock' => 5,
        ]);

        $this->getJson('/api/products?min_price=1000000&max_price=10000000')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $matched->id);
    }

    public function test_products_api_can_combine_search_and_category_filters(): void
    {
        $category1 = Category::create(['name' => 'Mobile']);
        $category2 = Category::create(['name' => 'Computer']);

        $matched = Product::create([
            'category_id' => $category1->id,
            'name' => 'Samsung Galaxy',
            'price' => 10000000,
            'stock' => 5,
        ]);

        Product::create([
            'category_id' => $category2->id,
            'name' => 'Samsung Laptop',
            'price' => 20000000,
            'stock' => 3,
        ]);

        $this->getJson(
            '/api/products?search=Samsung&category_id=' . $category1->id
        )
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $matched->id);
    }

    public function test_products_api_rejects_invalid_price_range(): void
    {
        $this->getJson('/api/products?min_price=10000000&max_price=5000000')
            ->assertUnprocessable();
    }

    public function test_products_api_can_control_pagination_size(): void
    {
        $category = Category::create(['name' => 'Mobile']);

        foreach (range(1, 12) as $number) {
            Product::create([
                'category_id' => $category->id,
                'name' => 'Product ' . $number,
                'price' => 1000 * $number,
                'stock' => 5,
            ]);
        }

        $this->getJson('/api/products?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'products')
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 12);
    }

    public function test_products_api_can_filter_in_stock_products(): void
    {
        $category = Category::create(['name' => 'Mobile']);

        $available = Product::create([
            'category_id' => $category->id,
            'name' => 'Available Product',
            'price' => 1000,
            'stock' => 5,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Unavailable Product',
            'price' => 2000,
            'stock' => 0,
        ]);

        $this->getJson('/api/products?in_stock=true')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $available->id);
    }

    public function test_products_api_can_filter_out_of_stock_products(): void
    {
        $category = Category::create(['name' => 'Mobile']);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Available Product',
            'price' => 1000,
            'stock' => 5,
        ]);

        $unavailable = Product::create([
            'category_id' => $category->id,
            'name' => 'Unavailable Product',
            'price' => 2000,
            'stock' => 0,
        ]);

        $this->getJson('/api/products?in_stock=false')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $unavailable->id);
    }

    public function test_products_api_can_sort_newest_first(): void
    {
        $category = Category::create(['name' => 'Mobile']);

        $oldest = Product::create([
            'category_id' => $category->id,
            'name' => 'Old Product',
            'price' => 1000,
            'stock' => 5,
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $newest = Product::create([
            'category_id' => $category->id,
            'name' => 'New Product',
            'price' => 2000,
            'stock' => 5,
            'created_at' => '2026-02-01 10:00:00',
        ]);

        $this->getJson('/api/products?sort_by=newest')
            ->assertOk()
            ->assertJsonPath('products.0.id', $newest->id)
            ->assertJsonPath('products.1.id', $oldest->id);
    }

    public function test_products_api_can_sort_oldest_first(): void
    {
        $category = Category::create(['name' => 'Mobile']);

        $oldest = Product::create([
            'category_id' => $category->id,
            'name' => 'Old Product',
            'price' => 1000,
            'stock' => 5,
            'created_at' => '2026-01-01 10:00:00',
        ]);

        $newest = Product::create([
            'category_id' => $category->id,
            'name' => 'New Product',
            'price' => 2000,
            'stock' => 5,
            'created_at' => '2026-02-01 10:00:00',
        ]);

        $this->getJson('/api/products?sort_by=oldest')
            ->assertOk()
            ->assertJsonPath('products.0.id', $oldest->id)
            ->assertJsonPath('products.1.id', $newest->id);
    }
}
