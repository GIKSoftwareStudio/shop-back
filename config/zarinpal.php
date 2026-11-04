<?php

$sandbox = filter_var(
    env('ZARINPAL_SANDBOX', true),
    FILTER_VALIDATE_BOOLEAN
);

return [
    'merchant_id' => env('ZARINPAL_MERCHANT_ID'),

    'sandbox' => $sandbox,

    'currency' => env('ZARINPAL_CURRENCY', 'IRT'),

    'max_amount' => (int) env('ZARINPAL_MAX_AMOUNT', 200000000),

    'base_url' => rtrim(
        env(
            'ZARINPAL_BASE_URL',
            $sandbox
                ? 'https://sandbox.zarinpal.com/pg/v4/payment'
                : 'https://api.zarinpal.com/pg/v4/payment'
        ),
        '/'
    ),
];
