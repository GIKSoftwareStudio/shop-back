<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// این Resource اطلاعات محصول را مخصوص پنل مدیریت نمایش می‌دهد و تعداد موجودی رزروشده را نیز اضافه می‌کند.
class AdminProductResource extends ProductResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            // تعداد محصولاتی که برای سفارش‌های در انتظار پرداخت رزرو شده‌اند.
            'reserved_stock' => (int) $this->reserved_stock,
        ]);
    }
}
