<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->name,
            'price' => $this->price,
            // استفاده از ریسورس دسته‌بندی برای ارتباط
            'category' => new CategoryResource($this->whenLoaded('category')),
            // استفاده از کالکشن ریسورس تصاویر برای ارتباط چندتایی
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at,
        ];
    }
}
