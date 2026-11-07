<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private string $assistantToken = 'test-assistant-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.assistant.token' => $this->assistantToken,
        ]);
    }

    private function assistantRequest(string $url)
    {
        return $this->withToken($this->assistantToken)
            ->getJson($url);
    }

    public function test_assistant_api_rejects_request_without_token(): void
    {
        $this->getJson('/api/assistant/products')
            ->assertUnauthorized();
    }

    public function test_assistant_api_rejects_invalid_token(): void
    {
        $this->withToken('wrong-token')
            ->getJson('/api/assistant/products')
            ->assertUnauthorized();
    }

    public function test_assistant_api_returns_products_with_valid_token(): void
    {
        $category = Category::create([
            'name' => 'Mobile',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Samsung Galaxy',
            'price' => 10000000,
            'stock' => 5,
        ]);

        $this->assistantRequest('/api/assistant/products')
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.title', 'Samsung Galaxy')
            ->assertJsonPath('products.0.category.name', 'Mobile');
    }

    public function test_assistant_api_can_search_products_by_name(): void
    {
        $category = Category::create([
            'name' => 'Mobile',
        ]);

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

        $this->assistantRequest('/api/assistant/products?search=Samsung')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $matched->id);
    }

    public function test_assistant_api_can_filter_by_price_range(): void
    {
        $category = Category::create([
            'name' => 'Mobile',
        ]);

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

        $this->assistantRequest(
            '/api/assistant/products?min_price=1000000&max_price=10000000'
        )
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $matched->id);
    }

    public function test_assistant_api_can_combine_search_and_category_filters(): void
    {
        $category1 = Category::create([
            'name' => 'Mobile',
        ]);

        $category2 = Category::create([
            'name' => 'Computer',
        ]);

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

        $this->assistantRequest(
            '/api/assistant/products?search=Samsung&category_id=' . $category1->id
        )
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $matched->id);
    }

    public function test_assistant_api_rejects_invalid_price_range(): void
    {
        $this->assistantRequest(
            '/api/assistant/products?min_price=10000000&max_price=5000000'
        )
            ->assertUnprocessable();
    }
}
