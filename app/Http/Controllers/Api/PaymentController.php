<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function simulate(string $token)
    {
        $order = Order::where('payment_token', $token)->firstOrFail();

        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'این سفارش در انتظار پرداخت نیست.',
                'status' => $order->status,
            ], 409);
        }

        return response()->json([
            'message' => 'پرداخت شبیه‌سازی‌شده آماده است.',
            'order_id' => $order->id,
            'amount' => $order->total_amount,
            'payment_token' => $order->payment_token,
            'instructions' => [
                'برای پرداخت موفق، POST /api/webhooks/payment را با status=success فراخوانی کنید.',
                'برای پرداخت ناموفق، POST /api/webhooks/payment را با status=failure فراخوانی کنید.',
            ],
        ]);
    }

    public function webhook(Request $request)
    {
        $secret = config('services.payment.webhook_secret');

        if (
            empty($secret) ||
            ! hash_equals($secret, (string) $request->header('X-Payment-Secret'))
        ) {
            return response()->json([
                'message' => 'دسترسی غیرمجاز است.',
            ], 401);
        }

        $validated = $request->validate([
            'payment_token' => ['required', 'uuid'],
            'status' => ['required', 'in:success,failure'],
        ]);

        return DB::transaction(function () use ($validated) {
            $order = Order::where(
                'payment_token',
                $validated['payment_token']
            )->lockForUpdate()->first();

            if (!$order) {
                throw ValidationException::withMessages([
                    'payment_token' => 'توکن پرداخت معتبر نیست.',
                ]);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'message' => 'وضعیت این سفارش قبلاً تعیین شده است.',
                    'order_id' => $order->id,
                    'status' => $order->status,
                ], 409);
            }

            if ($validated['status'] === 'success') {
                $order->update([
                    'status' => 'paid',
                ]);

                return response()->json([
                    'message' => 'پرداخت با موفقیت تأیید شد.',
                    'order_id' => $order->id,
                    'status' => $order->status,
                ]);
            }

            foreach ($order->items as $item) {
                $product = \App\Models\Product::whereKey(
                    $item->product_id
                )->lockForUpdate()->first();

                if ($product) {
                    $product->increment('stock', $item->quantity);
                }
            }

            $order->update([
                'status' => 'failed',
            ]);

            return response()->json([
                'message' => 'پرداخت ناموفق بود؛ موجودی کالاها برگشت داده شد.',
                'order_id' => $order->id,
                'status' => $order->status,
            ]);
        });
    }
}
