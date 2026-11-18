<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // تعیین درگاه پرداخت؛ در صورت عدم ارسال، زرین‌پال انتخاب می‌شود.
            'payment_driver' => [
                'sometimes',
                'required',
                'string',
                'in:zarinpal,zibal',
            ],

            'items' => ['required', 'array', 'min:1'],

            'items.*' => [
                'required',
                'array',
                'required_array_keys:product_id,quantity',
            ],

            'items.*.product_id' => [
                'required',
                'integer',
                'min:1',
                'distinct',
                'exists:products,id',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.price' => ['prohibited'],
        ];
    }
}
