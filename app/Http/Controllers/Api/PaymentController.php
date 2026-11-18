<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PaymentFinalizationService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Throwable;

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
                return $this->failureResponse(
                    $request,
                    'پارامترهای Callback معتبر نیستند.',
                    400,
                    [],
                    null,
                    'invalid'
                );
            }

            if ($request->query('Status') === 'NOK') {
                return $this->failureResponse(
                    $request,
                    'نتیجه پرداخت قطعی نیست؛ سفارش در انتظار بررسی باقی ماند.',
                    202,
                    ['status' => 'pending'],
                    $this->findOrder($paymentDriver, $transactionId),
                    'canceled'
                );
            }
        }

        // استخراج پارامترهای Callback زیبال.
        elseif ($request->has('trackId')) {
            $paymentDriver = 'zibal';
            $transactionId = $request->query('trackId');

            if (! in_array((string) $request->query('success'), ['1', '2'], true)) {
                return $this->failureResponse(
                    $request,
                    'پرداخت در زیبال موفق اعلام نشده است.',
                    202,
                    ['status' => 'pending'],
                    $this->findOrder($paymentDriver, $transactionId),
                    'canceled'
                );
            }
        }

        // بررسی اعتبار شناسه تراکنش.
        if (
            ! is_string($transactionId)
            && ! is_int($transactionId)
        ) {
            return $this->failureResponse(
                $request,
                'پارامترهای Callback معتبر نیستند.',
                400,
                [],
                null,
                'invalid'
            );
        }

        if (trim((string) $transactionId) === '') {
            return $this->failureResponse(
                $request,
                'شناسه تراکنش معتبر نیست.',
                400,
                [],
                null,
                'invalid'
            );
        }

        // پیدا کردن سفارش متعلق به تراکنش بازگشتی.
        $order = $this->findOrder($paymentDriver, $transactionId);

        if (! $order) {
            return $this->failureResponse(
                $request,
                'سفارش یافت نشد.',
                404,
                [],
                null,
                'not-found'
            );
        }

        // جلوگیری از تأیید مجدد سفارش پرداخت‌شده.
        if ($order->status === 'paid') {
            return $this->successResponse($request, $order, true);
        }

        if ($order->status !== 'pending') {
            return $this->failureResponse(
                $request,
                'این سفارش قابل پرداخت نیست.',
                409,
                ['status' => $order->status],
                $order,
                'not-payable'
            );
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
            return $this->failureResponse(
                $request,
                'بررسی پرداخت در حال انجام است یا سفارش نیازمند بررسی مجدد است.',
                202,
                [
                    'order_id' => $order->id,
                    'status' => 'pending',
                ],
                $order,
                'in-progress'
            );
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

            return $this->failureResponse(
                $request,
                'تراکنش قبلاً در درگاه تأیید شده است؛ سفارش برای بررسی دستی باقی ماند.',
                202,
                [
                    'order_id' => $order->id,
                    'status' => 'pending',
                ],
                $order,
                'uncertain'
            );
        } catch (Throwable $exception) {
            Log::error('Payment verification failed.', [
                'order_id' => $order->id,
                'payment_driver' => $paymentDriver,
                'exception_class' => get_class($exception),
                'exception' => $exception->getMessage(),
            ]);

            $this->releaseVerificationLock($order, $verificationToken);

            return $this->failureResponse(
                $request,
                'تأیید پرداخت انجام نشد یا نتیجه آن قطعی نیست؛ سفارش نیازمند بررسی است.',
                503,
                [
                    'order_id' => $order->id,
                    'status' => 'pending',
                ],
                $order,
                'uncertain'
            );
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

            return $this->failureResponse(
                $request,
                'تأیید پرداخت انجام شد، اما نهایی‌سازی سفارش نیازمند بررسی مجدد است.',
                503,
                [
                    'order_id' => $order->id,
                    'status' => 'pending',
                ],
                $order,
                'not-confirmed'
            );
        }

        return $this->successResponse($request, $finalizedOrder, false);
    }

    /**
     * پاسخ موفق؛ برای مرورگر به صفحه پرداخت موفق و برای API به JSON.
     */
    private function successResponse(
        Request $request,
        Order $order,
        bool $alreadyPaid
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $alreadyPaid
                    ? 'این پرداخت قبلاً تأیید شده است.'
                    : 'پرداخت با موفقیت تأیید شد.',
                'order_id' => $order->id,
                'ref_id' => $order->payment_reference,
                'paid_at' => $order->paid_at,
                'status' => 'paid',
            ]);
        }

        return $this->redirectToResult('payment.success', $order);
    }

    /**
     * پاسخ ناموفق یا در انتظار بررسی؛ برای مرورگر به صفحه پرداخت ناموفق و برای API به JSON.
     */
    private function failureResponse(
        Request $request,
        string $message,
        int $status,
        array $data = [],
        ?Order $order = null,
        string $reason = 'failed'
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json(
                ['message' => $message] + $data,
                $status
            );
        }

        return $this->redirectToResult('payment.failed', $order, $reason);
    }

    /**
     * ساخت لینک امضاشده صفحه نتیجه تا اطلاعات سفارش قابل دستکاری نباشد.
     */
    private function redirectToResult(
        string $route,
        ?Order $order = null,
        ?string $reason = null
    ): RedirectResponse {
        $parameters = [];

        if ($order) {
            $parameters['order'] = $order->id;
        }

        if ($reason !== null) {
            $parameters['reason'] = $reason;
        }

        return redirect()->to(URL::signedRoute($route, $parameters));
    }

    /**
     * یافتن سفارش بر اساس شناسه تراکنش و درگاه.
     */
    private function findOrder(
        ?string $paymentDriver,
        mixed $transactionId
    ): ?Order {
        if (
            $paymentDriver === null
            || (! is_string($transactionId) && ! is_int($transactionId))
            || trim((string) $transactionId) === ''
        ) {
            return null;
        }

        return Order::where('payment_token', (string) $transactionId)
            ->where('payment_driver', $paymentDriver)
            ->first();
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
