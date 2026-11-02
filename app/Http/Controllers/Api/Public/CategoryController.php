<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index()
    {
        return CategoryResource::collection(Category::all());
    }

    public function show(Category $category)
    {
        $category->load('products.images');

        return response()->json([
            'data' => [
                'id' => $category->id,
                'name' => $category->name,
                'created_at' => $category->created_at,
                'products' => ProductResource::collection($category->products),
            ],
        ]);
    }
}
