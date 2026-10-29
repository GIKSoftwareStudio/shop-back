<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;


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

                if (!$product) {
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
                'payment_token' => (string) Str::uuid(),
            ]);

            $order->items()->createMany($orderItems);

            return $order;
        });

        return response()->json([
            'message' => 'سفارش با موفقیت ثبت شد.',
            'order' => [
                'id' => $order->id,
                'total_amount' => $order->total_amount,
                'status' => $order->status,
                'payment_token' => $order->payment_token,
            ],
            'payment_url' => url(
                '/api/payment/simulate/' . $order->payment_token
            ),
        ], 201);
    }
}
