<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PaymentResultPageTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'A00000000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zarinpal.sandbox' => true,
            'zarinpal.currency' => 'IRT',
            'zarinpal.merchant_id' => 'test-merchant-id',
            'zarinpal.base_url' => 'https://sandbox.zarinpal.com/pg/v4/payment',
            'payment.drivers.zibal.merchantId' => 'test-zibal-merchant',
            'payment.drivers.zibal.apiVerificationUrl' => 'https://gateway.zibal.ir/v1/verify',
        ]);
    }

    private function createOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'total_amount' => 150,
            'status' => 'pending',
            'stock_status' => 'legacy_deducted',
            'payment_token' => self::AUTHORITY,
            'payment_driver' => 'zarinpal',
        ], $attributes));
    }

    private function fakeZarinpalStatus(string $status): void
    {
        Http::fake([
            '*/inquiry.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'status' => $status,
                ],
                'errors' => [],
            ], 200),
        ]);
    }

    public function test_browser_callback_redirects_to_success_page_after_payment(): void
    {
        // تأیید تراکنش بدون تماس واقعی با درگاه.
        $this->mock(PaymentGatewayService::class, function ($mock) {
            $mock->shouldReceive('verify')
                ->once()
                ->andReturn('123456789');
        });

        $order = $this->createOrder();

        $response = $this->get(
            '/api/payment/callback?Authority='
            .self::AUTHORITY
            .'&Status=OK'
        );

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertStringContainsString(route('payment.success'), $location);

        $this->get($location)
            ->assertOk()
            ->assertSee('پرداخت با موفقیت انجام شد')
            ->assertSee('123456789')
            ->assertSee('زرین‌پال')
            ->assertSee('150 تومان');

        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_failure_page_asks_gateway_and_marks_cancelled_payment_as_failed(): void
    {
        $this->fakeZarinpalStatus('FAILED');

        $order = $this->createOrder();

        $response = $this->get(
            '/api/payment/callback?Authority='
            .self::AUTHORITY
            .'&Status=NOK'
        );

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertStringContainsString(route('payment.failed'), $location);

        $this->get($location)
            ->assertOk()
            ->assertSee('پرداخت ناموفق بود')
            ->assertSee('#'.$order->id)
            ->assertSee('ناموفق')
            ->assertSee('پرداخت شما انجام نشد.');

        // نتیجه از درگاه استعلام و سفارش نهایی شده است.
        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_success_page_asks_gateway_and_confirms_pending_order(): void
    {
        Http::fake([
            '*/inquiry.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'status' => 'PAID',
                ],
                'errors' => [],
            ], 200),
            '*/verify.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'ref_id' => 123456789,
                ],
                'errors' => [],
            ], 200),
        ]);

        $order = $this->createOrder();

        $url = URL::signedRoute('payment.success', [
            'order' => $order->id,
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('پرداخت با موفقیت انجام شد')
            ->assertSee('123456789');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('123456789', $order->fresh()->payment_reference);
    }

    public function test_success_page_keeps_order_pending_when_gateway_is_not_final(): void
    {
        $this->fakeZarinpalStatus('IN_BANK');

        $order = $this->createOrder();

        $url = URL::signedRoute('payment.success', [
            'order' => $order->id,
        ]);

        $response = $this->get($url);

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertStringContainsString(route('payment.failed'), $location);

        $this->get($location)
            ->assertOk()
            ->assertSee('پرداخت ناموفق بود')
            ->assertSee('در حال بررسی');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_failure_page_confirms_pending_zibal_payment_from_gateway(): void
    {
        Http::fake([
            'https://gateway.zibal.ir/v1/verify' => Http::response([
                'result' => 100,
                'refNumber' => 987654321,
                'message' => 'Success',
            ], 200),
        ]);

        $order = $this->createOrder([
            'payment_driver' => 'zibal',
            'payment_token' => '987654321',
        ]);

        $url = URL::signedRoute('payment.failed', [
            'order' => $order->id,
            'reason' => 'canceled',
        ]);

        $response = $this->get($url);

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertStringContainsString(route('payment.success'), $location);

        $this->get($location)
            ->assertOk()
            ->assertSee('پرداخت با موفقیت انجام شد')
            ->assertSee('987654321');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('987654321', $order->fresh()->payment_reference);
    }

    public function test_failure_page_redirects_to_success_page_for_paid_order(): void
    {
        $order = $this->createOrder([
            'status' => 'paid',
            'payment_reference' => '123456789',
            'paid_at' => now(),
        ]);

        $url = URL::signedRoute('payment.failed', [
            'order' => $order->id,
            'reason' => 'canceled',
        ]);

        $response = $this->get($url);

        $response->assertRedirect();

        $location = $response->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertStringContainsString(route('payment.success'), $location);

        $this->get($location)
            ->assertOk()
            ->assertSee('پرداخت با موفقیت انجام شد');
    }

    public function test_missing_order_shows_failure_page_instead_of_404(): void
    {
        $successUrl = URL::signedRoute('payment.success', [
            'order' => 999999,
        ]);

        $this->get($successUrl)
            ->assertOk()
            ->assertSee('پرداخت ناموفق بود')
            ->assertSee('سفارش مربوط به این پرداخت پیدا نشد.');

        $failureUrl = URL::signedRoute('payment.failed', [
            'order' => 999999,
            'reason' => 'canceled',
        ]);

        $this->get($failureUrl)
            ->assertOk()
            ->assertSee('پرداخت ناموفق بود')
            ->assertSee('سفارش مربوط به این پرداخت پیدا نشد.');
    }

    public function test_payment_result_pages_reject_links_without_a_valid_signature(): void
    {
        $order = $this->createOrder();

        $this->get('/payment/success?order='.$order->id)
            ->assertOk()
            ->assertSee('لینک نتیجه پرداخت معتبر نیست');

        $this->get('/payment/failed?order='.$order->id.'&reason=canceled')
            ->assertOk()
            ->assertSee('لینک نتیجه پرداخت معتبر نیست');
    }
}
