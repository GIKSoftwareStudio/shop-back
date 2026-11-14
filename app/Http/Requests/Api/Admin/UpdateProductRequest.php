<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

// این کلاس اطلاعات ارسالی مدیر برای ویرایش محصول را اعتبارسنجی می‌کند و اجازه نمی‌دهد موجودی واقعی از موجودی رزروشده کمتر شود.
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => 'sometimes|required|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'price' => 'sometimes|required|numeric|min:0',

            // موجودی جدید باید عدد صحیح و غیرمنفی باشد و از تعداد رزروشده کمتر نشود.
            'stock' => [
                'sometimes',
                'required',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    $product = $this->route('product');

                    if (
                        $product
                        && (int) $value < (int) $product->reserved_stock
                    ) {
                        $fail(
                            'موجودی محصول نمی‌تواند کمتر از موجودی رزروشده باشد.'
                        );
                    }
                },
            ],
        ];
    }
}
