<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    // لیست تمام دسته‌بندی‌ها
    public function index()
    {
        return CategoryResource::collection(Category::all());
    }
}
