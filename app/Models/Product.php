<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; // <--- ۱. این خط باید بالای فایل باشد
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Product extends Model
{
    use HasFactory;
    protected $fillable = ['category_id', 'name', 'price', 'stock'];

    // ارتباط برعکس با دسته‌بندی
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // A Product hasMany ProductImages
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }
}
