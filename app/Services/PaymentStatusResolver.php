<?php

namespace App\Services;

use AbolfazlAhmadi\LaravelZarinpal\Services\ZarinpalService;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PaymentStatusResolver
{
    public function __construct(
        private ZarinpalPaymentStatusService $zarinpalStatus,
        private ZibalPaymentStatusService $zibalStatus,
        private PaymentFinalizationService $finalizationService,
        private ZarinpalService $zarinpal
    ) {}

    /**
     * استعلام وضعیت نهایی سفارش از درگاه و نهایی‌سازی آن در صورت امکان.
     */
    public function refresh(Order $order): Order
    {
        // فقط سفارش‌های در انتظار پرداخت نیاز به استعلام دارند.
        if ($order->status !== 'pending') {
            return $order;
        }

        if (! in_array($order->payment_driver, ['zarinpal', 'zibal'], true)) {
            return $order;
        }

        $transactionId = trim((string) $order->payment_token);

        if ($transactionId === '') {
            return $order;
        }

        // قفل موقت برای جلوگیری از استعلام و نهایی‌سازی هم‌زمان.
        $verificationToken = (string) Str::uuid();
        $staleVerificationTime = now()->subMinutes(2);

        $claimed = Order::whereKey($order->id)
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
                'verification_started_at' => now(),
                'verification_token' => $verificationToken,
            ]);

        if ($claimed !== 1) {
            return $order->fresh();
        }

        try {
            $result = $this->resolveReferenceId($order, $transactionId);

            // نتیجه نامشخص است؛ سفارش برای بررسی بعدی در انتظار می‌ماند.
            if ($result === null) {
                $this->releaseLock($order, $verificationToken);

                return $order->fresh();
            }

            // درگاه پرداخت را قطعاً ناموفق اعلام کرده است.
            if ($result === false) {
                return $this->finalizationService->failPayment(
                    $order,
                    $verificationToken
                );
            }

            // پرداخت از سوی درگاه تأیید شده و شناسه مرجع دریافت شده است.
            return $this->finalizationService->confirmPayment(
                $order,
                $result,
                $verificationToken
            );
        } catch (Throwable $exception) {
            Log::warning('Gateway payment status check failed.', [
                'order_id' => $order->id,
                'payment_driver' => $order->payment_driver,
                'exception_class' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            $this->releaseLock($order, $verificationToken);

            return $order->fresh();
        }
    }

    /**
     * دریافت شناسه مرجع از درگاه.
     *
     * @return string|false|null شناسه مرجع، false برای پرداخت قطعاً ناموفق و null برای نتیجه نامشخص
     */
    private function resolveReferenceId(
        Order $order,
        string $transactionId
    ): string|false|null {
        if ($order->payment_driver === 'zarinpal') {
            $result = $this->zarinpalStatus->inquiry($transactionId);
            $status = strtoupper(trim($result['status']));

            if ($status === 'FAILED') {
                return false;
            }

            if (! in_array($status, ['PAID', 'VERIFIED'], true)) {
                return null;
            }

            $verification = $this->zarinpal->verify(
                $transactionId,
                (int) $order->total_amount
            );

            $referenceId = $verification['ref_id'] ?? null;

            if (
                (! is_string($referenceId) && ! is_int($referenceId))
                || trim((string) $referenceId) === ''
            ) {
                throw new RuntimeException(
                    'Zarinpal verification returned an invalid reference ID.'
                );
            }

            return (string) $referenceId;
        }

        // استعلام وضعیت زیبال.
        $result = $this->zibalStatus->verify($transactionId);

        return match ($result['code']) {
            100 => (string) $result['ref_id'],
            202 => false,
            default => null,
        };
    }

    // آزاد کردن قفل استعلام در صورت نامشخص بودن نتیجه.
    private function releaseLock(
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
