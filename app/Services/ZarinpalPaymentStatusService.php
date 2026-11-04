<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZarinpalPaymentStatusService
{
    public function inquiry(string $authority): array
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->retry(2, 300)
            ->post(
                config('zarinpal.base_url') . '/inquiry.json',
                [
                    'merchant_id' => config('zarinpal.merchant_id'),
                    'authority' => $authority,
                ]
            );

        $response->throw();

        $result = $response->json();
        $data = is_array($result) ? ($result['data'] ?? null) : null;

        if (
            ! is_array($data)
            || ! isset($data['code'], $data['status'])
            || ! is_numeric($data['code'])
            || ! is_string($data['status'])
            || trim($data['status']) === ''
        ) {
            throw new RuntimeException(
                'Invalid Zarinpal inquiry response.'
            );
        }

        return [
            'code' => (int) $data['code'],
            'status' => trim($data['status']),
            'message' => isset($data['message'])
            && is_string($data['message'])
                ? $data['message']
                : null,
        ];
    }
}
