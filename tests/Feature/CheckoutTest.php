<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.payment.webhook_secret' => 'test-secret',
        ]);

        $this->withHeader('X-Payment-Secret', 'test-secret');
    }

    public function test_payment_webhook_rejects_request_without_secret(): void
    {
        $response = $this
            ->withoutHeader('X-Payment-Secret')
            ->postJson('/api/webhooks/payment', [
                'payment_token' => '550e8400-e29b-41d4-a716-446655440000',
                'status' => 'success',
            ]);

        $response->assertUnauthorized();
    }

    public function test_payment_webhook_rejects_invalid_secret(): void
    {
        $response = $this
            ->withHeader('X-Payment-Secret', 'wrong-secret')
            ->postJson('/api/webhooks/payment', [
                'payment_token' => '550e8400-e29b-41d4-a716-446655440000',
                'status' => 'success',
            ]);

        $response->assertUnauthorized();
    }

    public function test_checkout_fails_when_stock_is_insufficient(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 2,
        ]);

        $response = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                ],
            ],
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 2,
        ]);
    }

    public function test_checkout_creates_order_and_reduces_stock(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 5,
        ]);

        $response = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.total_amount', '200.00')
            ->assertJsonPath('order.status', 'pending');

        $this->assertDatabaseCount('orders', 1);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
        ]);
    }

    public function test_successful_payment_marks_order_as_paid(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 5,
        ]);

        $checkoutResponse = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $checkoutResponse->assertCreated();

        $token = $checkoutResponse->json('order.payment_token');

        $paymentResponse = $this->postJson('/api/webhooks/payment', [
            'payment_token' => $token,
            'status' => 'success',
        ]);

        $paymentResponse->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertDatabaseHas('orders', [
            'payment_token' => $token,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 4,
        ]);
    }

    public function test_payment_webhook_rejects_invalid_status(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => '100.00',
            'stock' => 5,
        ]);

        $checkoutResponse = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $checkoutResponse->assertCreated();

        $token = $checkoutResponse->json('order.payment_token');

        $response = $this->postJson('/api/webhooks/payment', [
            'payment_token' => $token,
            'status' => 'unknown',
        ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('orders', [
            'payment_token' => $token,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 4,
        ]);
    }

    public function test_failed_payment_restores_product_stock(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 5,
        ]);

        $checkoutResponse = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $checkoutResponse->assertCreated();

        $token = $checkoutResponse->json('order.payment_token');

        // موجودی پس از ثبت سفارش باید 3 باشد.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
        ]);

        $paymentResponse = $this->postJson('/api/webhooks/payment', [
            'payment_token' => $token,
            'status' => 'failure',
        ]);

        $paymentResponse->assertOk()
            ->assertJsonPath('status', 'failed');

        // پس از پرداخت ناموفق، موجودی باید به 5 برگردد.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
        ]);

        $this->assertDatabaseHas('orders', [
            'payment_token' => $token,
            'status' => 'failed',
        ]);
    }

    public function test_failed_payment_webhook_cannot_restore_stock_twice(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 5,
        ]);

        $checkoutResponse = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $checkoutResponse->assertCreated();

        $token = $checkoutResponse->json('order.payment_token');

        $payload = [
            'payment_token' => $token,
            'status' => 'failure',
        ];

        // بار اول: پرداخت ناموفق و بازگشت موجودی
        $this->postJson('/api/webhooks/payment', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'failed');

        // بار دوم: درخواست تکراری باید رد شود
        $this->postJson('/api/webhooks/payment', $payload)
            ->assertStatus(409);

        // موجودی باید دقیقاً 5 باقی مانده باشد.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
        ]);

        // سفارش باید همچنان failed باشد.
        $this->assertDatabaseHas('orders', [
            'payment_token' => $token,
            'status' => 'failed',
        ]);
    }

    public function test_checkout_rejects_client_supplied_price(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 5,
        ]);

        $response = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
        ]);
    }

    public function test_successful_payment_webhook_cannot_be_processed_twice(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 5,
        ]);

        // ثبت سفارش یک عدد محصول
        $checkoutResponse = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $checkoutResponse->assertCreated();

        $token = $checkoutResponse->json('order.payment_token');

        $payload = [
            'payment_token' => $token,
            'status' => 'success',
        ];

        // بار اول: پرداخت موفق
        $this->postJson('/api/webhooks/payment', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        // بار دوم: وب‌هوک تکراری باید رد شود
        $this->postJson('/api/webhooks/payment', $payload)
            ->assertStatus(409);

        // وضعیت سفارش باید همچنان paid باشد
        $this->assertDatabaseHas('orders', [
            'payment_token' => $token,
            'status' => 'paid',
        ]);

        // موجودی نباید دوباره تغییر کند
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 4,
        ]);
    }

    public function test_checkout_rejects_nonexistent_product(): void
    {
        $response = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => 999999,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_invalid_quantity(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 5,
        ]);

        $response = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 0,
                ],
            ],
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
        ]);
    }

    public function test_checkout_rolls_back_stock_when_a_later_item_has_insufficient_stock(): void
    {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        $firstProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'First Product',
            'price' => 100.00,
            'stock' => 5,
        ]);

        $secondProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Second Product',
            'price' => 100.00,
            'stock' => 1,
        ]);

        $response = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $firstProduct->id,
                    'quantity' => 2,
                ],
                [
                    'product_id' => $secondProduct->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);

        $this->assertDatabaseHas('products', [
            'id' => $firstProduct->id,
            'stock' => 5,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $secondProduct->id,
            'stock' => 1,
        ]);
    }

    public function test_checkout_calculates_decimal_prices_correctly(): void
    {
        $category = Category::create([
            'name' => 'Decimal Price Category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Decimal Price Product',
            'price' => '19.99',
            'stock' => 10,
        ]);

        $response = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.total_amount', '59.97');
    }
}
