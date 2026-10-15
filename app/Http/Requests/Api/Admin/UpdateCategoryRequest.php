<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }


    public function rules(): array
    {
        // در زمان ویرایش، نام دسته‌بندی باید یکتا باشد اما رکورد خودش نادیده گرفته شود
        $categoryId = $this->route('category')->id ?? $this->route('category');

        return [
            'name' => 'required|string|max:255|unique:categories,name,' . $categoryId,
        ];
    }
}
