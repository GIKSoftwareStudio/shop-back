<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use App\Services\PaymentGatewayService;
use RuntimeException;
use Mockery;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'A00000000000000000000000000000000000';

    // آماده‌سازی تنظیمات و Mock سرویس درگاه برای تست‌های Checkout.
    // آماده‌سازی تنظیمات و شبیه‌سازی هر دو درگاه پرداخت.
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zarinpal.max_amount' => 200000000,
            'payment.drivers.zarinpal.mode' => 'sandbox',
        ]);

        // شبیه‌سازی سرویس پرداخت بدون ارسال درخواست واقعی به درگاه.
        $this->mock(PaymentGatewayService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->andReturnUsing(function (Order $order) {
                    $transactionId = $order->payment_driver === 'zibal'
                        ? '987654321'
                        : self::AUTHORITY;

                    return [
                        'transaction_id' => $transactionId,
                        'payment_url' => $order->payment_driver === 'zibal'
                            ? 'https://gateway.zibal.ir/start/' . $transactionId
                            : 'https://sandbox.zarinpal.com/pg/StartPay/' . $transactionId,
                    ];
                });

            $mock->shouldReceive('verify')
                ->andReturnUsing(function (Order $order) {
                    return $order->payment_driver === 'zibal'
                        ? '9876543210'
                        : '123456789';
                });
        });
    }

    private function createProduct(
        string $price = '100.00',
        int $stock = 5
    ): Product {
        $category = Category::create([
            'name' => 'Test Category',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'price' => $price,
            'stock' => $stock,
        ]);
    }

    private function checkout(Product $product, int $quantity = 1)
    {
        Sanctum::actingAs(User::factory()->create());

        return $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ],
            ],
        ]);
    }

    private function getCallback(string $status = 'OK')
    {
        return $this->getJson(
            '/api/payment/callback?Authority='
            . self::AUTHORITY
            . '&Status='
            . $status
        );
    }

    public function test_guest_can_checkout_without_user_information(): void
    {
        $product = $this->createProduct('100.00', 5);

        $response = $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('order.total_amount', '100.00')
            ->assertJsonPath('order.status', 'pending');

        $this->assertDatabaseHas('orders', [
            'user_id' => null,
            'total_amount' => 100,
            'status' => 'pending',
            'payment_token' => self::AUTHORITY,
        ]);

        // بررسی می‌کند موجودی واقعی ثابت مانده و یک عدد از موجودی رزرو شده است.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 1,
        ]);

        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_checkout_creates_order_and_reserves_stock(): void
    {
        $product = $this->createProduct('100.00', 5);

        $response = $this->checkout($product, 2);

        $response->assertCreated()
            ->assertJsonPath('order.total_amount', '200.00')
            ->assertJsonPath('order.status', 'pending');

        $this->assertDatabaseHas('orders', [
            'total_amount' => 200,
            'status' => 'pending',
            'payment_token' => self::AUTHORITY,
        ]);

        // بررسی می‌کند که موجودی واقعی حفظ شده و تعداد سفارش رزرو شده است.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 2,
        ]);

        $this->assertDatabaseCount('order_items', 1);

        $this->assertSame(
            'https://sandbox.zarinpal.com/pg/StartPay/' . self::AUTHORITY,
            $response->json('payment_url')
        );

    }

    public function test_checkout_rejects_insufficient_stock(): void
    {
        $product = $this->createProduct('100.00', 2);

        $this->checkout($product, 3)
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 2,
        ]);
    }

    public function test_checkout_rejects_decimal_product_price(): void
    {
        $product = $this->createProduct('19.99', 10);

        $this->checkout($product, 3)
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
        ]);
    }

    public function test_checkout_rejects_client_supplied_price(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $product = $this->createProduct();

        $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 1,
                ],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_invalid_quantity(): void
    {
        $product = $this->createProduct();

        $this->checkout($product, 0)
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_rejects_nonexistent_product(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/checkout', [
            'items' => [
                [
                    'product_id' => 999999,
                    'quantity' => 1,
                ],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_successful_payment_marks_order_as_paid(): void
    {
        $product = $this->createProduct();

        $this->checkout($product)->assertCreated();

        $this->getCallback()
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('ref_id', '123456789');
        $order = Order::firstOrFail();

        $this->assertSame('paid', $order->status);
        $this->assertSame('123456789', $order->payment_reference);
        $this->assertNotNull($order->paid_at);

        // بررسی می‌کند که پس از پرداخت موفق، موجودی واقعی کم و رزرو آزاد شده باشد.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 4,
            'reserved_stock' => 0,
        ]);

    }

    public function test_successful_callback_cannot_process_payment_twice(): void
    {
        $product = $this->createProduct();

        $this->checkout($product)->assertCreated();

        $this->getCallback()->assertOk();

        // Callback تکراری باید بدون Verify مجدد پاسخ موفق بدهد.
        $this->getCallback()
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        // بررسی می‌کند که Callback تکراری موجودی رزروشده را دوباره تغییر ندهد.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 4,
            'reserved_stock' => 0,
        ]);

        $this->assertDatabaseHas('orders', [
            'payment_token' => self::AUTHORITY,
            'status' => 'paid',
            'payment_reference' => '123456789',
        ]);
    }

    public function test_nok_callback_keeps_order_pending_and_stock_reserved(): void
    {
        $product = $this->createProduct('100.00', 5);

        $this->checkout($product, 2)->assertCreated();

        $this->getCallback('NOK')
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        // بررسی می‌کند که با Callback ناموفق، موجودی واقعی تغییر نکند و رزرو حفظ شود.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 2,
        ]);

        $this->assertDatabaseHas('orders', [
            'payment_token' => self::AUTHORITY,
            'status' => 'pending',
        ]);
    }

    public function test_repeated_nok_callbacks_keep_order_pending_and_stock_reserved(): void
    {
        $product = $this->createProduct('100.00', 5);

        $this->checkout($product, 2)->assertCreated();

        $this->getCallback('NOK')
            ->assertStatus(202);

        $this->getCallback('NOK')
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        // بررسی می‌کند که Callback ناموفق تکراری باعث تغییر موجودی یا رزرو نشود.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 2,
        ]);

        $this->assertDatabaseHas('orders', [
            'payment_token' => self::AUTHORITY,
            'status' => 'pending',
        ]);
    }

    public function test_callback_rejects_missing_authority(): void
    {
        $this->getJson('/api/payment/callback?Status=OK')
            ->assertStatus(400);
    }

    public function test_callback_rejects_unknown_authority(): void
    {
        $this->getJson(
            '/api/payment/callback?Authority=UNKNOWN&Status=OK'
        )->assertNotFound();
    }

    public function test_callback_rejects_invalid_status(): void
    {
        $product = $this->createProduct();

        $this->checkout($product)->assertCreated();

        $this->getCallback('UNKNOWN')
            ->assertStatus(400);

        $this->assertDatabaseHas('orders', [
            'payment_token' => self::AUTHORITY,
            'status' => 'pending',
        ]);
    }

    public function test_checkout_keeps_order_pending_on_connection_failure(): void
    {
        // شبیه‌سازی خطای ارتباط هنگام شروع پرداخت.
        $this->mock(PaymentGatewayService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andThrow(new RuntimeException('Simulated connection failure'));
        });

        $product = $this->createProduct('100.00', 5);

        $response = $this->checkout($product);

        $response->assertStatus(503)
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('orders', [
            'status' => 'pending',
            'payment_token' => null,
        ]);

        // بررسی می‌کند که رزرو حفظ شود و موجودی واقعی تغییر نکند.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 1,
        ]);
    }

    public function test_verify_connection_failure_keeps_order_pending(): void
    {
        // شبیه‌سازی شروع موفق پرداخت و خطا هنگام تأیید تراکنش.
        $this->mock(PaymentGatewayService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn([
                    'transaction_id' => self::AUTHORITY,
                    'payment_url' => 'https://sandbox.zarinpal.com/pg/StartPay/' . self::AUTHORITY,
                ]);

            $mock->shouldReceive('verify')
                ->once()
                ->andThrow(new RuntimeException('Simulated verification failure'));
        });

        $product = $this->createProduct();

        $this->checkout($product)->assertCreated();

        $this->getCallback()
            ->assertStatus(503)
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('orders', [
            'payment_token' => self::AUTHORITY,
            'status' => 'pending',
            'payment_reference' => null,
            'verification_started_at' => null,
            'verification_token' => null,
        ]);

        // بررسی می‌کند که هنگام خطای تأیید، رزرو حفظ شود.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 1,
        ]);
    }

    public function test_payment_remains_pending_when_verification_has_no_reference_id(): void
    {
        // شبیه‌سازی پرداخت موفق اولیه و برنگشتن شناسه مرجع از درگاه.
        $this->mock(PaymentGatewayService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn([
                    'transaction_id' => self::AUTHORITY,
                    'payment_url' => 'https://sandbox.zarinpal.com/pg/StartPay/' . self::AUTHORITY,
                ]);

            $mock->shouldReceive('verify')
                ->once()
                ->andThrow(new RuntimeException('درگاه شناسه مرجع معتبری برنگرداند.'));
        });

        $product = $this->createProduct('100.00', 5);

        $this->checkout($product)->assertCreated();

        $this->getCallback()
            ->assertStatus(503)
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('orders', [
            'payment_token' => self::AUTHORITY,
            'status' => 'pending',
            'payment_reference' => null,
        ]);

        // بررسی می‌کند که بدون شناسه مرجع، رزرو حفظ شود.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 1,
        ]);
    }

    public function test_payment_remains_pending_on_unexpected_verification_error(): void
    {
        // شبیه‌سازی خطای غیرمنتظره در مرحله تأیید تراکنش.
        $this->mock(PaymentGatewayService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn([
                    'transaction_id' => self::AUTHORITY,
                    'payment_url' => 'https://sandbox.zarinpal.com/pg/StartPay/' . self::AUTHORITY,
                ]);

            $mock->shouldReceive('verify')
                ->once()
                ->andThrow(new \Error('Simulated unexpected verification error'));
        });

        $product = $this->createProduct('100.00', 5);

        $this->checkout($product)->assertCreated();

        $this->getCallback()
            ->assertStatus(503)
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('orders', [
            'payment_token' => self::AUTHORITY,
            'status' => 'pending',
            'payment_reference' => null,
        ]);

        // بررسی می‌کند که هنگام خطای غیرمنتظره، رزرو حفظ شود.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 1,
        ]);
    }

    public function test_checkout_rolls_back_all_stock_when_a_later_item_has_insufficient_stock(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $firstProduct = $this->createProduct('100.00', 5);
        $secondProduct = $this->createProduct('200.00', 1);

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

        // بررسی می‌کند که رزرو محصول اول پس از Rollback به حالت اولیه برگردد.
        $this->assertDatabaseHas('products', [
            'id' => $firstProduct->id,
            'stock' => 5,
            'reserved_stock' => 0,
        ]);

        // بررسی می‌کند که موجودی و رزرو محصول دوم نیز بدون تغییر باقی بماند.
        $this->assertDatabaseHas('products', [
            'id' => $secondProduct->id,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);
    }

    public function test_checkout_keeps_order_pending_and_stock_reserved_when_zarinpal_request_result_is_unconfirmed(): void
    {
        // شبیه‌سازی ناموفق بودن درخواست ایجاد پرداخت در درگاه.
        $this->mock(PaymentGatewayService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andThrow(new RuntimeException('Payment request rejected'));
        });

        $product = $this->createProduct('100.00', 5);

        $response = $this->checkout($product, 2);

        $response->assertStatus(503);

        $this->assertDatabaseHas('orders', [
            'status' => 'pending',
            'total_amount' => 200,
        ]);

        // بررسی می‌کند که رزرو موجودی در نتیجه نامشخص حفظ شود.
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,
            'reserved_stock' => 2,
        ]);
    }
}
