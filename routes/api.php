<?php

use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\Public\CategoryController;
use App\Http\Controllers\Api\Public\ProductController;
use App\Http\Controllers\Api\Public\CartController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// وضعیت API
Route::get('/status', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Shop API is running',
    ]);
});

// احراز هویت
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');

// اطلاعات کاربر واردشده
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

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

        Route::get('/orders', [OrderController::class, 'index']);
    });

// بازگشت از درگاه پرداخت زرین‌پال
Route::get('/payment/callback', [PaymentController::class, 'callback']);
