<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request)
    {
        $items = $request->validated()['items'];

        $order = DB::transaction(function () use ($items) {
            $totalCents = 0;
            $orderItems = [];

            foreach ($items as $item) {
                $product = Product::whereKey($item['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'یکی از محصولات دیگر وجود ندارد.',
                    ]);
                }

                $quantity = (int) $item['quantity'];

                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "موجودی محصول {$product->name} کافی نیست.",
                    ]);
                }

                $priceParts = explode('.', (string) $product->price);
                $whole = (int) $priceParts[0];
                $fraction = (int) str_pad(
                    substr($priceParts[1] ?? '0', 0, 2),
                    2,
                    '0'
                );

                $unitPriceCents = ($whole * 100) + $fraction;
                $totalCents += $unitPriceCents * $quantity;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                ];

                $product->decrement('stock', $quantity);
            }

            $totalAmount = intdiv($totalCents, 100)
                . '.'
                . str_pad((string) ($totalCents % 100), 2, '0', STR_PAD_LEFT);

            $order = Order::create([
                'user_id' => auth()->id(),
                'total_amount' => $totalAmount,
                'status' => 'pending',
                'payment_token' => null,
            ]);

            $order->items()->createMany($orderItems);

            return $order;
        });

        // درخواست به زرین‌پال (مستقیم با Http)
        $merchantId = config('zarinpal.merchant_id');
        $callbackUrl = url('/api/payment/callback');
        $amountInRial = (int) round(((float) $order->total_amount) * 10); // تومان → ریال

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://sandbox.zarinpal.com/pg/v4/payment/request.json', [
            'merchant_id' => $merchantId,
            'amount' => $amountInRial,
            'description' => 'پرداخت سفارش #' . $order->id,
            'callback_url' => $callbackUrl,
        ]);

        $zarinpalResult = $response->json();

        if (
            $response->failed() ||
            empty($zarinpalResult['data']['authority'])
        ) {
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

            return response()->json([
                'message' => 'خطا در اتصال به درگاه پرداخت. لطفاً دوباره تلاش کنید.',
                'error' => $zarinpalResult['errors'] ?? 'unknown',
            ], 502);
        }

        $authority = $zarinpalResult['data']['authority'];
        $order->update(['payment_token' => $authority]);

        return response()->json([
            'message' => 'سفارش با موفقیت ثبت شد.',
            'order' => [
                'id' => $order->id,
                'total_amount' => $order->total_amount,
                'status' => $order->status,
            ],
            'payment_url' => 'https://sandbox.zarinpal.com/pg/StartPay/' . $authority,
        ], 201);
    }
}
