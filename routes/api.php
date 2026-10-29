<?php

use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Public\CategoryController;
use App\Http\Controllers\Api\Public\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\Admin\OrderController;

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

// پرداخت شبیه‌سازی‌شده
Route::get('/payment/simulate/{token}', [PaymentController::class, 'simulate']);

// دریافت نتیجه پرداخت از وب‌هوک
Route::post('/webhooks/payment', [PaymentController::class, 'webhook']);

// ===== نیازمند احراز هویت (کاربر عادی) =====
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/checkout', [CheckoutController::class, 'store']);
});

// ===== نیازمند احراز هویت + نقش ادمین =====
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::apiResource('categories', AdminCategoryController::class);
    Route::apiResource('products', AdminProductController::class);
    Route::get('/orders', [OrderController::class, 'index']);
});
