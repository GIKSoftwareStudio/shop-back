<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentFinalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PaymentFinalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentFinalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PaymentFinalizationService::class);
    }

    // ساخت سفارش در انتظار پرداخت همراه با محصول و موجودی رزروشده
    private function createPendingOrder(
        string $stockStatus = 'reserved',
        int $quantity = 2
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

// تنظیم موجودی رزروشده برای داده آزمایشی
        $product->reserved_stock = $stockStatus === 'reserved' ? $quantity : 0;
        $product->save();

        $order = Order::create([
            'user_id' => null,
            'total_amount' => 100 * $quantity,
            'status' => 'pending',
            'stock_status' => $stockStatus,
            'verification_token' => 'test-verification-token',
            'verification_started_at' => now(),
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 100,
        ]);

        return [$order->fresh(), $product->fresh()];
    }

    public function test_confirm_payment_deducts_stock_and_releases_reservation(): void
    {
        [$order, $product] = $this->createPendingOrder();

        $result = $this->service->confirmPayment(
            $order,
            '123456789',
            'test-verification-token'
        );

        $this->assertSame('paid', $result->status);
        $this->assertSame('deducted', $result->stock_status);
        $this->assertSame('123456789', $result->payment_reference);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
    }

    public function test_fail_payment_releases_reserved_stock(): void
    {
        [$order, $product] = $this->createPendingOrder();

        $result = $this->service->failPayment(
            $order,
            'test-verification-token'
        );

        $this->assertSame('failed', $result->status);
        $this->assertSame('released', $result->stock_status);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
    }

    public function test_confirm_payment_does_not_deduct_stock_twice(): void
    {
        [$order, $product] = $this->createPendingOrder();

        $this->service->confirmPayment(
            $order,
            '123456789',
            'test-verification-token'
        );

        $result = $this->service->confirmPayment(
            $order,
            '987654321',
            'test-verification-token'
        );

        $this->assertSame('paid', $result->status);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 3,
            'reserved_stock' => 0,
        ]);
    }

    public function test_fail_payment_does_not_release_stock_twice(): void
    {
        [$order, $product] = $this->createPendingOrder();

        $this->service->failPayment(
            $order,
            'test-verification-token'
        );

        $result = $this->service->failPayment(
            $order,
            'test-verification-token'
        );

        $this->assertSame('failed', $result->status);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
    }

    public function test_confirm_payment_rejects_invalid_verification_token(): void
    {
        [$order, $product] = $this->createPendingOrder();

        try {
            $this->service->confirmPayment(
                $order,
                '123456789',
                'invalid-token'
            );

            $this->fail('Expected a RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Payment verification lock is invalid or expired.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 2,
        ]);
    }
}
