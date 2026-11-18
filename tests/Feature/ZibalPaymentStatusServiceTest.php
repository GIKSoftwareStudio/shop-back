<?php

namespace Tests\Feature;

use App\Services\ZibalPaymentStatusService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Tests\TestCase;

class ZibalPaymentStatusServiceTest extends TestCase
{
    public function test_it_returns_a_valid_successful_verification_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 100,
                'message' => 'Success',
                'refNumber' => 123456789,
            ], 200),
        ]);

        $result = app(ZibalPaymentStatusService::class)
            ->verify('987654321');

        $this->assertSame(100, $result['code']);
        $this->assertSame('123456789', $result['ref_id']);
        $this->assertSame('Success', $result['message']);

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/v1/verify')
                && $request['merchant'] === config('payment.drivers.zibal.merchantId')
                && $request['trackId'] === 987654321;
        });
    }

    public function test_it_returns_failed_payment_status_without_reference_id(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 202,
                'message' => 'Payment failed',
            ], 200),
        ]);

        $result = app(ZibalPaymentStatusService::class)
            ->verify('987654321');

        $this->assertSame(202, $result['code']);
        $this->assertNull($result['ref_id']);
    }

    public function test_it_returns_previously_verified_status(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 201,
                'message' => 'Previously verified',
            ], 200),
        ]);

        $result = app(ZibalPaymentStatusService::class)
            ->verify('987654321');

        $this->assertSame(201, $result['code']);
        $this->assertNull($result['ref_id']);
    }

    public function test_it_rejects_an_invalid_verification_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'Invalid response',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        app(ZibalPaymentStatusService::class)
            ->verify('987654321');
    }

    public function test_it_rejects_successful_response_without_reference_number(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 100,
                'message' => 'Success',
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        app(ZibalPaymentStatusService::class)
            ->verify('987654321');
    }

    public function test_it_throws_on_http_errors(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'Server error',
            ], 500),
        ]);

        $this->expectException(RequestException::class);

        app(ZibalPaymentStatusService::class)
            ->verify('987654321');
    }
}
