<?php

namespace App\Http\Controllers\Api;

use AbolfazlAhmadi\LaravelZarinpal\Services\ZarinpalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckoutController extends Controller
{
    public function store(
        CheckoutRequest $request,
        ZarinpalService $zarinpal
    ) {
        $items = $request->validated()['items'];

        $maxAmount = (int) config('zarinpal.max_amount');

        if ($maxAmount <= 0) {
            return response()->json([
                'message' => 'تنظیم سقف مبلغ پرداخت معتبر نیست.',
            ], 500);
        }


        $order = DB::transaction(function () use ($items, $maxAmount) {
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
                'user_id' => auth()->id(),
                'total_amount' => $amountInToman,
                'status' => 'pending',
                'stock_status' => 'reserved',
                'payment_token' => null,
            ]);

            $order->items()->createMany($orderItems);

            return $order;
        });


        $callbackUrl = url('/api/payment/callback');

        try {
            $payment = $zarinpal->request(
                (int) $order->total_amount,
                'پرداخت سفارش #' . $order->id,
                $callbackUrl
            );
        } catch (RuntimeException $exception) {
            Log::warning('Zarinpal payment request result is unconfirmed.', [
                'order_id' => $order->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'نتیجه درخواست پرداخت از درگاه قطعی نیست؛ سفارش در انتظار بررسی باقی مانده است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Zarinpal payment request has an uncertain result.', [
                'order_id' => $order->id,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'ارتباط با درگاه پرداخت قطع شد. وضعیت سفارش نیازمند بررسی است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        } catch (Throwable $exception) {
            Log::error('Unexpected error during Zarinpal payment request.', [
                'order_id' => $order->id,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'خطای پیش‌بینی‌نشده هنگام ارتباط با درگاه رخ داد. وضعیت سفارش نیازمند بررسی است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        }

        $authority = $payment['authority'] ?? null;
        $paymentUrl = $payment['url'] ?? null;

        $expectedHost = config('zarinpal.sandbox')
            ? 'sandbox.zarinpal.com'
            : 'payment.zarinpal.com';

        $urlParts = is_string($paymentUrl) ? parse_url($paymentUrl) : false;

        $validPaymentResponse =
            is_string($authority)
            && $authority !== ''
            && is_array($urlParts)
            && ($urlParts['scheme'] ?? null) === 'https'
            && ($urlParts['host'] ?? null) === $expectedHost
            && str_starts_with($urlParts['path'] ?? '', '/pg/StartPay/')
            && basename($urlParts['path'] ?? '') === $authority;

        if (! $validPaymentResponse) {
            Log::error('Zarinpal returned an invalid payment response.', [
                'order_id' => $order->id,
                'has_authority' => is_string($authority) && $authority !== '',
                'has_valid_url' => is_array($urlParts),
            ]);

            return response()->json([
                'message' => 'پاسخ درگاه پرداخت معتبر نیست. وضعیت سفارش نیازمند بررسی است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        }

        try {
            $order->update([
                'payment_token' => $authority,
            ]);
        } catch (Throwable $exception) {
            Log::critical('Could not save Zarinpal authority for order.', [
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
