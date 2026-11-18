<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PaymentResultPageTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHORITY = 'A00000000000000000000000000000000000';

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

    public function test_browser_callback_redirects_to_failure_page_when_payment_is_cancelled(): void
    {
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
            ->assertSee('در انتظار بررسی')
            ->assertSee('پرداخت توسط شما لغو شد');

        // پرداخت لغوشده طبق روند فعلی در انتظار بررسی باقی می‌ماند.
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_payment_result_pages_require_a_valid_signature(): void
    {
        $order = $this->createOrder();

        $this->get('/payment/success?order='.$order->id)
            ->assertForbidden();

        $this->get('/payment/failed?order='.$order->id.'&reason=canceled')
            ->assertForbidden();
    }

    public function test_success_page_redirects_to_failure_page_for_unpaid_order(): void
    {
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
            ->assertSee('پرداخت ناموفق بود');
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
}
