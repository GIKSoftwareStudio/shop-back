<?php

namespace App\Http\Controllers\Api;

use AbolfazlAhmadi\LaravelZarinpal\Services\ZarinpalService;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function callback(
        Request $request,
        ZarinpalService $zarinpal
    ) {
        $authority = $request->query('Authority');
        $status = $request->query('Status');

        if (! $authority || ! in_array($status, ['OK', 'NOK'], true)) {
            return response()->json([
                'message' => 'پارامترهای Callback معتبر نیستند.',
            ], 400);
        }

        $order = Order::where('payment_token', $authority)->first();

        if (! $order) {
            return response()->json([
                'message' => 'سفارش یافت نشد.',
            ], 404);
        }

        if ($order->status === 'paid') {
            return response()->json([
                'message' => 'این پرداخت قبلاً تأیید شده است.',
                'order_id' => $order->id,
                'ref_id' => $order->payment_reference,
                'status' => 'paid',
            ]);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'این سفارش قابل پرداخت نیست.',
                'status' => $order->status,
            ], 409);
        }

        if ($status === 'NOK') {
            Log::info('Zarinpal returned NOK callback. Order remains pending.', [
                'order_id' => $order->id,
            ]);

            return response()->json([
                'message' => 'نتیجه پرداخت قطعی نیست؛ سفارش برای بررسی مجدد در انتظار باقی ماند.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 202);
        }

        $verificationStartedAt = now();
        $staleVerificationTime = now()->subMinutes(2);
        $verificationToken = (string) Str::uuid();

        $verificationClaimed = Order::whereKey($order->id)
            ->where('status', 'pending')
            ->where(function ($query) use ($staleVerificationTime) {
                $query->whereNull('verification_started_at')
                    ->orWhere(
                        'verification_started_at',
                        '<=',
                        $staleVerificationTime
                    );
            })
            ->update([
                'verification_started_at' => $verificationStartedAt,
                'verification_token' => $verificationToken,
            ]);

        if ($verificationClaimed === 0) {
            return response()->json([
                'message' => 'بررسی پرداخت در حال انجام است یا سفارش نیازمند بررسی مجدد است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 202);
        }

        try {
            $verification = $zarinpal->verify(
                $authority,
                (int) $order->total_amount
            );
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Zarinpal verification connection failed.', [
                'order_id' => $order->id,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            $this->releaseVerificationLock($order, $verificationToken);

            return response()->json([
                'message' => 'ارتباط با درگاه برقرار نشد؛ وضعیت پرداخت نیازمند بررسی مجدد است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        } catch (RuntimeException $exception) {
            Log::warning('Zarinpal verification result is not confirmed.', [
                'order_id' => $order->id,
                'exception' => $exception->getMessage(),
            ]);

            $this->releaseVerificationLock($order, $verificationToken);

            return response()->json([
                'message' => 'نتیجه پرداخت قطعی نیست؛ سفارش برای بررسی مجدد در انتظار باقی ماند.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        } catch (Throwable $exception) {
            Log::error('Unexpected error during Zarinpal verification.', [
                'order_id' => $order->id,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            $this->releaseVerificationLock($order, $verificationToken);

            return response()->json([
                'message' => 'خطای پیش‌بینی‌نشده در بررسی پرداخت رخ داد. وضعیت سفارش نیازمند بررسی است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        }

        $refId = $verification['ref_id'] ?? null;

        if (
            (! is_string($refId) && ! is_int($refId))
            || trim((string) $refId) === ''
        ) {
            Log::error('Zarinpal verification returned an invalid reference ID.', [
                'order_id' => $order->id,
            ]);

            $this->releaseVerificationLock($order, $verificationToken);

            return response()->json([
                'message' => 'پاسخ تأیید درگاه معتبر نیست. وضعیت سفارش نیازمند بررسی است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        }

        $result = DB::transaction(function () use ($order, $refId, $verificationToken) {
            $updated = Order::whereKey($order->id)
                ->where('status', 'pending')
                ->where('verification_token', $verificationToken)
                ->update([
                    'status' => 'paid',
                    'payment_reference' => (string) $refId,
                    'paid_at' => now(),
                    'verification_started_at' => null,
                    'verification_token' => null,
                ]);

            $updatedOrder = Order::find($order->id);

            if ($updated === 1) {
                return [
                    'success' => true,
                    'order' => $updatedOrder,
                ];
            }

            if ($updatedOrder && $updatedOrder->status === 'paid') {
                return [
                    'already_paid' => true,
                    'order' => $updatedOrder,
                ];
            }

            return [
                'conflict' => true,
                'order' => $updatedOrder,
            ];
        });

        if (isset($result['conflict'])) {
            return response()->json([
                'message' => 'وضعیت سفارش در زمان تأیید پرداخت تغییر کرده است.',
                'status' => $result['order']?->status ?? 'not_found',
            ], 409);
        }

        return response()->json([
            'message' => 'پرداخت با موفقیت تأیید شد.',
            'order_id' => $result['order']->id,
            'ref_id' => $result['order']->payment_reference,
            'paid_at' => $result['order']->paid_at,
            'status' => 'paid',
        ]);
    }

    private function restoreStockAndFail(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $lockedOrder = Order::whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOrder || $lockedOrder->status !== 'pending') {
                return;
            }

            foreach ($lockedOrder->items as $item) {
                $product = Product::withTrashed()
                    ->whereKey($item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($product) {
                    $product->increment('stock', $item->quantity);
                }
            }

            $lockedOrder->update([
                'status' => 'failed',
            ]);
        });
    }

    private function releaseVerificationLock(
        Order $order,
        string $verificationToken
    ): void {
        Order::whereKey($order->id)
            ->where('status', 'pending')
            ->where('verification_token', $verificationToken)
            ->update([
                'verification_started_at' => null,
                'verification_token' => null,
            ]);
    }
}
