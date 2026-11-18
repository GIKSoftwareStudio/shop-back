<?php

namespace App\Services;

use App\Models\Order;
use RuntimeException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Payment;

class PaymentGatewayService
{
    /**
     * ایجاد درخواست پرداخت و دریافت شناسه تراکنش و آدرس درگاه.
     */
    public function start(Order $order, string $callbackUrl): array
    {
        // ساخت فاکتور پرداخت با مبلغ و اطلاعات سفارش.
        $invoice = new Invoice();

        $invoice->amount((int) $order->total_amount);
        $invoice->via($order->payment_driver);

        $invoice->detail([
            'order_id' => (string) $order->id,
            'description' => 'پرداخت سفارش #' . $order->id,
        ]);

        // ساخت نمونه مستقل Multipay برای جلوگیری از تداخل وضعیت درگاه‌ها.
        $payment = new Payment(config('payment'));

        // انتخاب درگاه و ثبت درخواست پرداخت.
        $payment
            ->via($order->payment_driver)
            ->callbackUrl($callbackUrl)
            ->purchase($invoice);

        // دریافت شناسه تراکنش ایجادشده توسط درگاه.
        $transactionId = $invoice->getTransactionId();

        if (
            (! is_string($transactionId) && ! is_int($transactionId))
            || (string) $transactionId === ''
        ) {
            throw new RuntimeException('درگاه شناسه تراکنش معتبری برنگرداند.');
        }

        // دریافت فرم هدایت کاربر به صفحه پرداخت.
        $paymentUrl = $payment->pay()->getAction();

        // اعتبارسنجی آدرس پرداخت بر اساس درگاه انتخاب‌شده.
        $urlParts = is_string($paymentUrl) ? parse_url($paymentUrl) : false;

        $expectedHost = match ($order->payment_driver) {
            'zarinpal' => config('payment.drivers.zarinpal.mode') === 'sandbox'
                ? 'sandbox.zarinpal.com'
                : 'www.zarinpal.com',
            'zibal' => 'gateway.zibal.ir',
            default => null,
        };

        $expectedPath = match ($order->payment_driver) {
            'zarinpal' => '/pg/StartPay/',
            'zibal' => '/start/',
            default => null,
        };

        $validPaymentUrl =
            $expectedHost !== null
            && is_array($urlParts)
            && ($urlParts['scheme'] ?? null) === 'https'
            && ($urlParts['host'] ?? null) === $expectedHost
            && str_starts_with($urlParts['path'] ?? '', $expectedPath)
            && basename($urlParts['path'] ?? '') === (string) $transactionId;

        if (! $validPaymentUrl) {
            throw new RuntimeException('آدرس بازگشت از درگاه پرداخت معتبر نیست.');
        }

        return [
            'transaction_id' => (string) $transactionId,
            'payment_url' => $paymentUrl,
        ];
    }

    /**
     * تأیید تراکنش با توجه به درگاه ثبت‌شده روی سفارش.
     */
    public function verify(Order $order, string $transactionId): string
    {
        // ساخت نمونه مستقل Multipay برای تأیید تراکنش.
        $payment = new Payment(config('payment'));

        // اجرای فرآیند Verify و دریافت رسید پرداخت.
        $receipt = $payment
            ->via($order->payment_driver)
            ->amount((int) $order->total_amount)
            ->transactionId($transactionId)
            ->verify();

        // استخراج و اعتبارسنجی شناسه مرجع پرداخت.
        $referenceId = $receipt->getReferenceId();

        if (trim($referenceId) === '') {
            throw new RuntimeException('درگاه شناسه مرجع معتبری برنگرداند.');
        }

        return $referenceId;
    }
}
