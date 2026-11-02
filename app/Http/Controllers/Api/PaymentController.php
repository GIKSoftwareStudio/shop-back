<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function callback(Request $request)
    {
        $authority = $request->query('Authority');
        $status = $request->query('Status');

        if (! $authority) {
            return response()->json(['message' => 'پارامتر Authority یافت نشد.'], 400);
        }

        $order = Order::where('payment_token', $authority)->first();

        if (! $order) {
            return response()->json(['message' => 'سفارش یافت نشد.'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'وضعیت این سفارش قبلاً تعیین شده است.',
                'status' => $order->status,
            ], 409);
        }

        if ($status !== 'OK') {
            $this->restoreStockAndFail($order);

            return response()->json([
                'message' => 'پرداخت ناموفق بود؛ موجودی کالاها برگشت داده شد.',
                'order_id' => $order->id,
                'status' => 'failed',
            ]);
        }

        // Verify با زرین‌پال
        $amountInRial = (int) round(((float) $order->total_amount) * 10);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://sandbox.zarinpal.com/pg/v4/payment/verify.json', [
            'merchant_id' => config('zarinpal.merchant_id'),
            'amount' => $amountInRial,
            'authority' => $authority,
        ]);

        $verifyResult = $response->json();

        if (
            $response->failed() ||
            ($verifyResult['data']['code'] ?? null) !== 100
        ) {
            $this->restoreStockAndFail($order);

            return response()->json([
                'message' => 'تأیید پرداخت ناموفق بود؛ موجودی کالاها برگشت داده شد.',
                'error' => $verifyResult['errors'] ?? 'unknown',
            ], 502);
        }

        $order->update(['status' => 'paid']);

        return response()->json([
            'message' => 'پرداخت با موفقیت تأیید شد.',
            'order_id' => $order->id,
            'ref_id' => $verifyResult['data']['ref_id'],
            'status' => 'paid',
        ]);
    }

    private function restoreStockAndFail(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                $product = Product::withTrashed()
                    ->whereKey($item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($product) {
                    $product->increment('stock', $item->quantity);
                }
            }

            $order->update(['status' => 'failed']);
        });
    }
}
