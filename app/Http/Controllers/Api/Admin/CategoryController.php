<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreCategoryRequest; // ایمپورت ریکوئست ساخت
use App\Http\Requests\Api\Admin\UpdateCategoryRequest; // ایمپورت ریکوئست ویرایش
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{

// ۱. لیست تمام دسته‌بندی‌ها
    public function index()
    {
        return CategoryResource::collection(Category::all());
    }


//    public function create()
//    {
//        //
//    }

    // ۲. ذخیره دسته‌بندی جدید
    // استفاده از StoreCategoryRequest
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json([
            'message' => 'Category created successfully',
            'data' => new CategoryResource($category)
        ], 201);
    }


    // ۳. نمایش یک دسته‌بندی خاص
    public function show(Category $category)
    {
        return new CategoryResource($category);
    }


//    public function edit(string $id)
//    {
//        //
//    }


    // استفاده از UpdateCategoryRequest
    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $category->update($request->validated());

        return response()->json([
            'message' => 'Category updated successfully',
            'data' => new CategoryResource($category)
        ]);
    }


    // ۵. حذف دسته‌بندی
    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully'
        ]);
    }
}
