<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\PaymentGatewayService;
use RuntimeException;
use Tests\Support\Payments\FakeGatewayDriver;
use Tests\TestCase;

class PaymentGatewayServiceTest extends TestCase
{
    // معرفی درایور آزمایشی به Multipay و آماده‌سازی تنظیمات تست.
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.map.zarinpal' => FakeGatewayDriver::class,
            'payment.map.zibal' => FakeGatewayDriver::class,

            'payment.drivers.zarinpal.mode' => 'sandbox',
            'payment.drivers.zarinpal.test_transaction_id' => 'ZARIN-TEST-123',
            'payment.drivers.zarinpal.test_payment_url' =>
                'https://sandbox.zarinpal.com/pg/StartPay/ZARIN-TEST-123',
            'payment.drivers.zarinpal.test_reference_id' => 'ZARIN-REF-123',

            'payment.drivers.zibal.test_transaction_id' => 'ZIBAL-TEST-456',
            'payment.drivers.zibal.test_payment_url' =>
                'https://gateway.zibal.ir/start/ZIBAL-TEST-456',
            'payment.drivers.zibal.test_reference_id' => 'ZIBAL-REF-456',
        ]);
    }

    // ساخت سفارش آزمایشی بدون نیاز به ذخیره در دیتابیس.
    private function makeOrder(string $driver = 'zarinpal'): Order
    {
        $order = new Order();

        $order->id = 100;
        $order->total_amount = '250000';
        $order->payment_driver = $driver;

        return $order;
    }

    // بررسی ایجاد تراکنش و دریافت آدرس معتبر زرین‌پال.
    public function test_it_starts_zarinpal_payment(): void
    {
        $result = app(PaymentGatewayService::class)->start(
            $this->makeOrder('zarinpal'),
            'https://example.com/api/payment/callback'
        );

        $this->assertSame('ZARIN-TEST-123', $result['transaction_id']);
        $this->assertSame(
            'https://sandbox.zarinpal.com/pg/StartPay/ZARIN-TEST-123',
            $result['payment_url']
        );
    }

    // بررسی ایجاد تراکنش و دریافت آدرس معتبر زیبال.
    public function test_it_starts_zibal_payment(): void
    {
        $result = app(PaymentGatewayService::class)->start(
            $this->makeOrder('zibal'),
            'https://example.com/api/payment/callback'
        );

        $this->assertSame('ZIBAL-TEST-456', $result['transaction_id']);
        $this->assertSame(
            'https://gateway.zibal.ir/start/ZIBAL-TEST-456',
            $result['payment_url']
        );
    }

    // جلوگیری از پذیرش آدرس پرداخت با دامنه غیرمجاز.
    public function test_it_rejects_invalid_payment_url(): void
    {
        config([
            'payment.drivers.zarinpal.test_payment_url' =>
                'https://malicious.example/pay/ZARIN-TEST-123',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('آدرس بازگشت از درگاه پرداخت معتبر نیست.');

        app(PaymentGatewayService::class)->start(
            $this->makeOrder('zarinpal'),
            'https://example.com/api/payment/callback'
        );
    }

    // جلوگیری از پذیرش تراکنش بدون شناسه معتبر.
    public function test_it_rejects_missing_transaction_id(): void
    {
        config([
            'payment.drivers.zarinpal.test_transaction_id' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('درگاه شناسه تراکنش معتبری برنگرداند.');

        app(PaymentGatewayService::class)->start(
            $this->makeOrder('zarinpal'),
            'https://example.com/api/payment/callback'
        );
    }

    // بررسی تأیید پرداخت و دریافت شناسه مرجع زرین‌پال.
    public function test_it_verifies_zarinpal_payment(): void
    {
        $referenceId = app(PaymentGatewayService::class)->verify(
            $this->makeOrder('zarinpal'),
            'ZARIN-TEST-123'
        );

        $this->assertSame('ZARIN-REF-123', $referenceId);
    }

    // بررسی تأیید پرداخت و دریافت شناسه مرجع زیبال.
    public function test_it_verifies_zibal_payment(): void
    {
        $referenceId = app(PaymentGatewayService::class)->verify(
            $this->makeOrder('zibal'),
            'ZIBAL-TEST-456'
        );

        $this->assertSame('ZIBAL-REF-456', $referenceId);
    }
}
