<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // اگر نخواهید تاریخ‌ها در خروجی باشند، می‌توانید آن‌ها را حذف کنید
            'created_at' => $this->created_at,
        ];
    }
}
