<?php

use App\Http\Controllers\PaymentResultController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// صفحه‌های نتیجه پرداخت؛ لینک‌ها امضاشده هستند تا اطلاعات سفارش قابل دستکاری نباشد.
Route::get('/payment/success', [PaymentResultController::class, 'success'])
    ->name('payment.success')
    ->middleware('signed');

Route::get('/payment/failed', [PaymentResultController::class, 'failed'])
    ->name('payment.failed')
    ->middleware('signed');
