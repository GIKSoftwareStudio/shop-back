<?php

use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Public\CategoryController;
use App\Http\Controllers\Api\Public\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/status', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Shop API is running',
    ]);
});

// ۱. آدرس لیست دسته‌بندی‌ها
Route::get('/categories', [CategoryController::class, 'index']);

// ۲. آدرس لیست محصولات و جزئیات یک محصول
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

// آدرس‌های بخش مدیریت (Admin Dashboard CRUD)
// نکته: اگر بعداً خواستید میانجی احراز هویت ادمین بگذارید، می‌توانید ->middleware('auth:sanctum') را به انتهای آن اضافه کنید.
Route::prefix('admin')->group(function () {
    Route::apiResource('categories', AdminCategoryController::class);
    Route::apiResource('products', AdminProductController::class);
});
