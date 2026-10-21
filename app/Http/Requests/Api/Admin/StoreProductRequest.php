<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{

    // اجازه دسترسی به این درخواست (بررسی احراز هویت)
    public function authorize(): bool
    {
        return true;
    }


    // قوانین اعتبارسنجی برای ساخت محصول جدید
    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'stock' => 'sometimes|integer|min:0',
        ];
    }
}
