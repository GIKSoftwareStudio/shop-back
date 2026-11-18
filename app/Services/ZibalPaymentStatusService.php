<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZibalPaymentStatusService
{
    /**
     * بررسی وضعیت نهایی تراکنش از طریق API تأیید زیبال.
     */
    public function verify(string $trackId): array
    {
        // ارسال درخواست تأیید به زیبال
        $response = Http::acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 300)
            ->post(
                config('payment.drivers.zibal.apiVerificationUrl'),
                [
                    'merchant' => config('payment.drivers.zibal.merchantId'),
                    'trackId' => (int) $trackId,
                ]
            );

        // خطاهای HTTP نباید به‌عنوان پرداخت ناموفق تفسیر شوند.
        $response->throw();

        $body = $response->json();

        // اعتبارسنجی ساختار پاسخ دریافتی
        if (
            ! is_array($body)
            || ! isset($body['result'])
            || ! is_numeric($body['result'])
        ) {
            throw new RuntimeException(
                'Invalid Zibal verification response.'
            );
        }

        $result = (int) $body['result'];

        // نتیجه موفق باید حتماً شماره مرجع معتبر داشته باشد.
        $refNumber = $body['refNumber'] ?? null;

        if (
            $result === 100
            && (
                ! is_string($refNumber)
                && ! is_int($refNumber)
                || trim((string) $refNumber) === ''
            )
        ) {
            throw new RuntimeException(
                'Zibal verification response has no valid reference number.'
            );
        }

        return [
            'code' => $result,
            'ref_id' => $result === 100 ? (string) $refNumber : null,
            'message' => isset($body['message']) && is_string($body['message'])
                ? $body['message']
                : null,
        ];
    }
}
