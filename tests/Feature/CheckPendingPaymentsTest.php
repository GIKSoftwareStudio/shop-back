<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckPendingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'S00000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zarinpal.sandbox' => true,
            'zarinpal.currency' => 'IRT',
            'zarinpal.merchant_id' => 'test-merchant-id',
            'zarinpal.max_amount' => 200000000,
        ]);
    }

    private function createPendingOrder(): array
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

        $product->reserved_stock = 2;
        $product->save();

        $order = Order::create([
            'user_id' => null,
            'total_amount' => 200,
            'status' => 'pending',
            'stock_status' => 'reserved',
            'payment_token' => self::AUTHORITY,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 100,
        ]);

        return [$order->fresh(), $product->fresh()];
    }

    public function test_it_releases_reserved_stock_when_payment_failed(): void
    {
        [$order, $product] = $this->createPendingOrder();

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'code' => 100,
                    'status' => 'FAILED',
                ],
                'errors' => [],
            ], 200),
        ]);

        $this->artisan('app:check-pending-payments')
            ->assertExitCode(1);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'failed',
            'stock_status' => 'released',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
    }

    public function test_it_confirms_payment_and_deducts_stock_when_verified(): void
    {
        [$order, $product] = $this->createPendingOrder();

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'data' => [
                        'code' => 100,
                        'status' => 'PAID',
                    ],
                    'errors' => [],
                ], 200)
                ->push([
                    'data' => [
                        'code' => 100,
                        'ref_id' => 123456789,
                    ],
                    'errors' => [],
                ], 200),
        ]);

        $this->artisan('app:check-pending-payments')
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'payment_reference' => '123456789',
            'stock_status' => 'deducted',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
    }

    public function test_it_keeps_order_pending_when_payment_is_in_bank(): void
    {
        [$order, $product] = $this->createPendingOrder();

        Http::fake([
            '*' => Http::response([
                'data' => [
                    'code' => 100,
                    'status' => 'IN_BANK',
                ],
                'errors' => [],
            ], 200),
        ]);

        $this->artisan('app:check-pending-payments')
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'verification_token' => null,
            'verification_started_at' => null,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 2,
        ]);
    }

    public function test_it_keeps_reservation_when_inquiry_fails(): void
    {
        [$order, $product] = $this->createPendingOrder();

        Http::fake([
            '*' => Http::response([
                'message' => 'Server error',
            ], 500),
        ]);

        $this->artisan('app:check-pending-payments')
            ->assertExitCode(1);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'verification_token' => null,
            'verification_started_at' => null,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 2,
        ]);
    }
}
