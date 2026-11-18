<?php

namespace Tests\Support\Payments;

use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PreviouslyVerifiedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;

class FakeGatewayDriver extends Driver
{
    // دریافت فاکتور و تنظیمات مخصوص درگاه آزمایشی.
    public function __construct(Invoice $invoice, array|object $settings)
    {
        $this->invoice = $invoice;
        $this->settings = (object) $settings;
    }

    // شبیه‌سازی ساخت تراکنش و ثبت شناسه آن روی فاکتور.
    public function purchase(): string|int|null
    {
        $transactionId = property_exists($this->settings, 'test_transaction_id')
            ? $this->settings->test_transaction_id
            : 'TEST-TRANSACTION';

        $this->invoice->transactionId($transactionId);

        return $transactionId;
    }

    // شبیه‌سازی آدرس هدایت کاربر به صفحه پرداخت.
    public function pay(): RedirectionForm
    {
        $paymentUrl = $this->settings->test_payment_url
            ?? 'https://sandbox.zarinpal.com/pg/StartPay/TEST-TRANSACTION';

        return $this->redirectWithForm($paymentUrl);
    }

    // شبیه‌سازی خطاهای مختلف درگاه برای آزمایش وضعیت پرداخت.
    public function verify(): ReceiptInterface
    {
        $exceptionCode = $this->settings->test_verify_exception_code ?? null;

        if ($exceptionCode === 201) {
            throw new PreviouslyVerifiedException(
                'Transaction has already been verified.',
                201
            );
        }

        if ($exceptionCode !== null) {
            throw new InvalidPaymentException(
                'Simulated payment verification error.',
                (int) $exceptionCode
            );
        }

        // تولید رسید آزمایشی در صورت موفقیت تأیید پرداخت.
        $referenceId = $this->settings->test_reference_id
            ?? 'TEST-REFERENCE';

        return new Receipt(
            $this->invoice->getDriver() ?? 'unknown',
            (string) $referenceId
        );
    }
}
