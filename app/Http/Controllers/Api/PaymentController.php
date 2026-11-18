<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentFinalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Throwable;
use App\Services\PaymentGatewayService;

class PaymentController extends Controller
{
    // دریافت Callback و تأیید پرداخت بر اساس درگاه انتخاب‌شده.
    public function callback(
        Request $request,
        PaymentFinalizationService $finalizationService,
        PaymentGatewayService $paymentGateway
    ) {
        // تشخیص شناسه تراکنش و وضعیت بازگشتی درگاه.
        $paymentDriver = null;
        $transactionId = null;

        // استخراج پارامترهای Callback زرین‌پال.
        if ($request->has('Authority')) {
            $paymentDriver = 'zarinpal';
            $transactionId = $request->query('Authority');

            if (! in_array($request->query('Status'), ['OK', 'NOK'], true)) {
                return response()->json([
                    'message' => 'پارامترهای Callback معتبر نیستند.',
                ], 400);
            }

            if ($request->query('Status') === 'NOK') {
                return response()->json([
                    'message' => 'نتیجه پرداخت قطعی نیست؛ سفارش در انتظار بررسی باقی ماند.',
                    'status' => 'pending',
                ], 202);
            }
        }

        // استخراج پارامترهای Callback زیبال.
        elseif ($request->has('trackId')) {
            $paymentDriver = 'zibal';
            $transactionId = $request->query('trackId');

            if (! in_array((string) $request->query('success'), ['1', '2'], true)) {
                return response()->json([
                    'message' => 'پرداخت در زیبال موفق اعلام نشده است.',
                    'status' => 'pending',
                ], 202);
            }
        }

        // بررسی اعتبار شناسه تراکنش.
        if (
            ! is_string($transactionId)
            && ! is_int($transactionId)
        ) {
            return response()->json([
                'message' => 'پارامترهای Callback معتبر نیستند.',
            ], 400);
        }

        if (trim((string) $transactionId) === '') {
            return response()->json([
                'message' => 'شناسه تراکنش معتبر نیست.',
            ], 400);
        }

        // پیدا کردن سفارش متعلق به تراکنش بازگشتی.
        $order = Order::where('payment_token', (string) $transactionId)
            ->where('payment_driver', $paymentDriver)
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'سفارش یافت نشد.',
            ], 404);
        }

        // جلوگیری از تأیید مجدد سفارش پرداخت‌شده.
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

        // ایجاد قفل برای جلوگیری از تأیید هم‌زمان یک سفارش.
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
            // تأیید تراکنش از طریق سرویس مشترک درگاه‌های پرداخت.
            $refId = $paymentGateway->verify(
                $order,
                (string) $transactionId
            );
        } catch (PreviouslyVerifiedException $exception) {
            // تراکنش قبلاً تأیید شده اما شناسه مرجع آن در دسترس نیست.
            Log::warning('Payment gateway reports a previously verified transaction.', [
                'order_id' => $order->id,
                'payment_driver' => $paymentDriver,
                'transaction_id' => $transactionId,
            ]);

            $this->releaseVerificationLock($order, $verificationToken);

            return response()->json([
                'message' => 'تراکنش قبلاً در درگاه تأیید شده است؛ سفارش برای بررسی دستی باقی ماند.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 202);
        } catch (Throwable $exception) {
            Log::error('Payment verification failed.', [
                'order_id' => $order->id,
                'payment_driver' => $paymentDriver,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            $this->releaseVerificationLock($order, $verificationToken);

            return response()->json([
                'message' => 'تأیید پرداخت انجام نشد یا نتیجه آن قطعی نیست؛ سفارش نیازمند بررسی است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        }

        try {
            // نهایی‌سازی پرداخت و کسر موجودی رزروشده.
            $finalizedOrder = $finalizationService->confirmPayment(
                $order,
                $refId,
                $verificationToken
            );
        } catch (Throwable $exception) {
            Log::error('Payment finalization failed.', [
                'order_id' => $order->id,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            $this->releaseVerificationLock($order, $verificationToken);

            return response()->json([
                'message' => 'تأیید پرداخت انجام شد، اما نهایی‌سازی سفارش نیازمند بررسی مجدد است.',
                'order_id' => $order->id,
                'status' => 'pending',
            ], 503);
        }

        return response()->json([
            'message' => 'پرداخت با موفقیت تأیید شد.',
            'order_id' => $finalizedOrder->id,
            'ref_id' => $finalizedOrder->payment_reference,
            'paid_at' => $finalizedOrder->paid_at,
            'status' => 'paid',
        ]);
    }

    // آزاد کردن قفل تأیید فقط برای همان درخواست مالک قفل.
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
