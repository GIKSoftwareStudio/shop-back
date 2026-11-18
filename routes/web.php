<?php

use App\Http\Controllers\PaymentResultController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// صفحه‌های نتیجه پرداخت؛ اعتبار لینک امضاشده داخل کنترلر بررسی می‌شود.
Route::get('/payment/success', [PaymentResultController::class, 'success'])
    ->name('payment.success');

Route::get('/payment/failed', [PaymentResultController::class, 'failed'])
    ->name('payment.failed');
