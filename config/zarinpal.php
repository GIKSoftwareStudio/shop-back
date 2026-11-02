<?php

return [

    'merchant_id' => env('ZARINPAL_MERCHANT_ID'),

    'sandbox' => env('ZARINPAL_SANDBOX', true),

    'currency' => env('ZARINPAL_CURRENCY', 'IRT'),

    'max_amount' => env('ZARINPAL_MAX_AMOUNT', 200000000),

    'base_url' => env(

        'ZARINPAL_BASE_URL',

        'https://sandbox.zarinpal.com/pg/v4/payment'

    ),

];