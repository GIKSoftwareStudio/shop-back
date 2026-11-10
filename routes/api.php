<?php

use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Assistant\ProductController as AssistantProductController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\Public\CategoryController;
use App\Http\Controllers\Api\Public\ProductController;
use App\Http\Controllers\Api\Public\CartController;
use Illuminate\Support\Facades\Route;

// وضعیت API
Route::get('/status', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Shop API is running',
    ]);
});

// دسته‌بندی‌ها
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

// محصولات
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::post('/cart/prices', [CartController::class, 'prices']);

// ثبت سفارش
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:5,1');

// بخش مدیریت
Route::prefix('admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function () {
        Route::apiResource('categories', AdminCategoryController::class);
        Route::apiResource('products', AdminProductController::class);

        Route::post('/products/{product}/images', [\App\Http\Controllers\Api\Admin\ProductImageController::class, 'store']);

        Route::put('/products/{product}/images/{image}', [\App\Http\Controllers\Api\Admin\ProductImageController::class, 'update']);

        Route::patch('/products/{product}/images/{image}/primary', [\App\Http\Controllers\Api\Admin\ProductImageController::class, 'setPrimary']);

        Route::delete('/products/{product}/images/{image}', [\App\Http\Controllers\Api\Admin\ProductImageController::class, 'destroy']);

        Route::get('/orders', [OrderController::class, 'index']);
    });

// دستیار فروشگاه
Route::prefix('assistant')
    ->middleware('assistant.token')
    ->group(function () {
        Route::get('/products', [AssistantProductController::class, 'index']);
    });

// بازگشت از درگاه پرداخت زرین‌پال
Route::get('/payment/callback', [PaymentController::class, 'callback']);
