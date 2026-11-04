<?php

namespace Tests\Feature;

use App\Services\ZarinpalPaymentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ZarinpalPaymentStatusServiceTest extends TestCase
{
    public function test_it_returns_a_valid_inquiry_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'code' => 100,
                    'message' => 'Success',
                    'status' => 'IN_BANK',
                ],
                'errors' => [],
            ], 200),
        ]);

        $result = app(ZarinpalPaymentStatusService::class)
            ->inquiry('S00000000000000000000000000000000000');

        $this->assertSame(100, $result['code']);
        $this->assertSame('IN_BANK', $result['status']);
        $this->assertSame('Success', $result['message']);

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/inquiry.json')
                && $request['merchant_id'] === config('zarinpal.merchant_id')
                && $request['authority'] === 'S00000000000000000000000000000000000';
        });
    }

    public function test_it_rejects_an_invalid_inquiry_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [],
                'errors' => [],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        app(ZarinpalPaymentStatusService::class)
            ->inquiry('S00000000000000000000000000000000000');
    }

    public function test_it_accepts_a_non_empty_unknown_payment_status(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'code' => 100,
                    'message' => 'Success',
                    'status' => 'UNKNOWN_STATUS',
                ],
                'errors' => [],
            ], 200),
        ]);

        $result = app(ZarinpalPaymentStatusService::class)
            ->inquiry('S00000000000000000000000000000000000');

        $this->assertSame(100, $result['code']);
        $this->assertSame('UNKNOWN_STATUS', $result['status']);
    }

    public function test_it_throws_on_http_errors(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'Server error',
            ], 500),
        ]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        app(ZarinpalPaymentStatusService::class)
            ->inquiry('S00000000000000000000000000000000000');
    }
}
