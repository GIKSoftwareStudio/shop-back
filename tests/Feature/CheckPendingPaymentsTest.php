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

        // تنظیم اطلاعات درگاه‌ها برای اجرای تست‌ها.
        config([
            'zarinpal.sandbox' => true,
            'zarinpal.currency' => 'IRT',
            'zarinpal.merchant_id' => 'test-merchant-id',
            'zarinpal.max_amount' => 200000000,
            'payment.drivers.zibal.merchantId' => 'test-zibal-merchant',
            'payment.drivers.zibal.apiVerificationUrl' => 'https://gateway.zibal.ir/v1/verify',
        ]);
    }

    // ساخت سفارش دارای موجودی رزروشده برای هر یک از درگاه‌ها.
    private function createPendingOrder(
        string $paymentDriver = 'zarinpal',
        string $paymentToken = self::AUTHORITY
    ): array {
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
            'payment_token' => $paymentToken,
            'payment_driver' => $paymentDriver,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 100,
        ]);

        return [$order->fresh(), $product->fresh()];
    }

    public function test_it_releases_reserved_stock_when_zarinpal_payment_failed(): void
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

    public function test_it_confirms_zarinpal_payment_and_deducts_stock_when_verified(): void
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

    public function test_it_keeps_zarinpal_order_pending_when_payment_is_in_bank(): void
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

    public function test_it_keeps_zarinpal_reservation_when_inquiry_fails(): void
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

    public function test_it_confirms_zibal_payment_and_deducts_stock_when_verified(): void
    {
        [$order, $product] = $this->createPendingOrder('zibal', '123456789');

        Http::fake([
            '*' => Http::response([
                'result' => 100,
                'refNumber' => 987654321,
                'message' => 'Success',
            ], 200),
        ]);

        $this->artisan('app:check-pending-payments')
            ->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'payment_reference' => '987654321',
            'stock_status' => 'deducted',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/verify')
                && $request['merchant'] === 'test-zibal-merchant'
                && $request['trackId'] === 123456789;
        });
    }

    public function test_it_releases_reserved_stock_when_zibal_payment_failed(): void
    {
        [$order, $product] = $this->createPendingOrder('zibal', '123456789');

        Http::fake([
            '*' => Http::response([
                'result' => 202,
                'message' => 'Payment failed',
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

    public function test_it_keeps_zibal_order_pending_when_transaction_was_previously_verified(): void
    {
        [$order, $product] = $this->createPendingOrder('zibal', '123456789');

        Http::fake([
            '*' => Http::response([
                'result' => 201,
                'message' => 'Previously verified',
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

    public function test_it_keeps_zibal_reservation_when_verification_request_fails(): void
    {
        [$order, $product] = $this->createPendingOrder('zibal', '123456789');

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
