<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; // <--- ۱. این خط باید بالای فایل باشد
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;  // اینم خودت نوشتی


class ProductImage extends Model  // متن داخل این کلاس هم خودت نوشتی خالی بود قبلش
{
    use HasFactory; // <--- این خط حتماً باید در هر ۳ مدل باشد
    protected $fillable = ['product_id', 'image_url', 'is_primary'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
