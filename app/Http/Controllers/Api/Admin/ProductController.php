<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreProductRequest;
use App\Http\Requests\Api\Admin\UpdateProductRequest;
use App\Http\Resources\AdminProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

// این کنترلر عملیات مدیریتی محصولات را انجام می‌دهد.
class ProductController extends Controller
{
    // دریافت فهرست محصولات همراه با دسته‌بندی، تصاویر و موجودی رزروشده.
    public function index()
    {
        $products = Product::with(['category', 'images'])->paginate(15);

        return AdminProductResource::collection($products);
    }

    // ایجاد محصول جدید.
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return response()->json([
            'message' => 'Product created successfully',
            'data' => new AdminProductResource($product->load(['category', 'images'])),
        ], 201);
    }

    // نمایش جزئیات یک محصول.
    public function show(Product $product)
    {
        return new AdminProductResource($product->load(['category', 'images']));
    }

    // ویرایش محصول با اعتبارسنجی اختصاصی.
    public function update(UpdateProductRequest $request, Product $product)
    {
        $product->update($request->validated());

        return response()->json([
            'message' => 'Product updated successfully',
            'data' => new AdminProductResource($product->load(['category', 'images'])),
        ]);
    }

    // حذف نرم محصول.
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }
}
