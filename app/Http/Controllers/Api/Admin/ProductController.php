<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreProductRequest; // ایمپورت کلاس ساخت
use App\Http\Requests\Api\Admin\UpdateProductRequest; // ایمپورت کلاس ویرایش
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{

    // ۱. لیست محصولات (برای ادمین)
    public function index()
    {
        $products = Product::with(['category', 'images'])->paginate(15);
        return ProductResource::collection($products);
    }


//    public function create()
//    {
//        //
//    }


    // ۲. ساخت محصول جدید (با استفاده از Form Request اختصاصی)
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return response()->json([
            'message' => 'Product created successfully',
            'data' => new ProductResource($product->load(['category', 'images']))
        ], 201);
    }


    // ۳. نمایش جزئیات یک محصول
    public function show(Product $product)
    {
        return new ProductResource($product->load(['category', 'images']));
    }


//    public function edit(string $id)
//    {
//        //
//    }


    // استفاده از UpdateProductRequest برای اعتبارسنجی ویرایش
    public function update(UpdateProductRequest $request, Product $product)
    {
        $product->update($request->validated());

        return response()->json([
            'message' => 'Product updated successfully',
            'data' => new ProductResource($product->load(['category', 'images']))
        ]);
    }


    // ۵. حذف محصول
    public function destroy(Product $product): JsonResponse
    {
        // حذف تصاویر مرتبط یا پاکسازی در صورت نیاز
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully'
        ]);
    }
}
