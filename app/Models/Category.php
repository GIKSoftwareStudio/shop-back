<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Category extends Model
{
    use HasFactory; // <--- این خط حتماً باید در هر ۳ مدل باشد
    protected $fillable = ['name'];



    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
