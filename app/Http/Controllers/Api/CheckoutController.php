<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Services\PaymentGatewayService;

class CheckoutController extends Controller
{
    // دریافت سرویس مشترک مدیریت درگاه‌های پرداخت.
    public function store(
        CheckoutRequest $request,
        PaymentGatewayService $paymentGateway
    ) {
        // دریافت اقلام سفارش و درگاه انتخاب‌شده توسط کاربر.
        $validated = $request->validated();
        $items = $validated['items'];
        $paymentDriver = $validated['payment_driver'] ?? 'zarinpal';

        $maxAmount = (int) config('zarinpal.max_amount');

        if ($maxAmount <= 0) {
            return response()->json([
                'message' => 'تنظیم سقف مبلغ پرداخت معتبر نیست.',
            ], 500);
        }


        $order = DB::transaction(function () use ($items, $maxAmount, $paymentDriver) {
            $totalCents = 0;
            $orderItems = [];

            foreach ($items as $item) {
                $quantity = (int) $item['quantity'];

                $product = Product::whereKey($item['product_id'])
                    ->first();

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'یکی از محصولات دیگر وجود ندارد.',
                    ]);
                }

                // رزرو اتمیک موجودی؛ موجودی واقعی تغییر نمی‌کند.
                $reserved = Product::whereKey($product->id)
                    ->whereRaw('(stock - reserved_stock) >= ?', [$quantity])
                    ->increment('reserved_stock', $quantity);

                if ($reserved !== 1) {
                    throw ValidationException::withMessages([
                        'items' => "موجودی قابل خرید محصول {$product->name} کافی نیست.",
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

                if ($unitPriceCents % 100 !== 0 || $unitPriceCents <= 0) {
                    throw ValidationException::withMessages([
                        'items' => "قیمت محصول {$product->name} باید عدد صحیح و مثبت تومان باشد.",
                    ]);
                }

                $totalCents += $unitPriceCents * $quantity;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                ];
            }

            // زرین‌پال مبلغ صحیح تومان دریافت می‌کند.
            if ($totalCents <= 0 || $totalCents % 100 !== 0) {
                throw ValidationException::withMessages([
                    'items' => 'مبلغ سفارش باید عدد صحیح و مثبت تومان باشد.',
                ]);
            }

            $amountInToman = intdiv($totalCents, 100);

            if ($amountInToman > $maxAmount) {
                throw ValidationException::withMessages([
                    'items' => 'مبلغ سفارش از سقف مجاز پرداخت بیشتر است.',
                ]);
            }

            $order = Order::create([
                'user_id' => auth()->id() ?? null,
                'total_amount' => $amountInToman,
                'status' => 'pending',
                'stock_status' => 'reserved',
                'payment_token' => null,
                'payment_driver' => $paymentDriver,
            ]);

            $order->items()->createMany($orderItems);

            return $order;
        });

        // ارسال درخواست پرداخت به درگاه انتخاب‌شده.
        $callbackUrl = url('/api/payment/callback');

        try {
            $payment = $paymentGateway->start($order, $callbackUrl);

            $transactionId = $payment['transaction_id'];
            $paymentUrl = $payment['payment_url'];
        } catch (Throwable $exception) {
            Log::error('Payment gateway request failed or returned an uncertain result.', [
                'order_id' => $order->id,
                'payment_driver' => $paymentDriver,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'نتیجه درخواست پرداخت قطعی نیست؛ سفارش در انتظار بررسی باقی مانده است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        }

        try {
            $order->update([
                'payment_token' => $transactionId,
            ]);
        } catch (Throwable $exception) {
            Log::critical('Could not save payment transaction ID for order.', [
                'order_id' => $order->id,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'درخواست پرداخت ایجاد شده اما ثبت اطلاعات آن با مشکل مواجه شد. سفارش نیازمند بررسی است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        }

        return response()->json([
            'message' => 'سفارش با موفقیت ثبت شد.',
            'order' => [
                'id' => $order->id,
                'total_amount' => $order->total_amount,
                'status' => $order->status,
            ],
            'payment_url' => $paymentUrl,
        ], 201);
    }
}
