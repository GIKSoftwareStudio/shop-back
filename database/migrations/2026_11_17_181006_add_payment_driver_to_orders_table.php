<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // افزودن درگاه پرداخت به سفارش‌ها؛ سفارش‌های قبلی زرین‌پال محسوب می‌شوند.
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_driver')
                ->default('zarinpal')
                ->after('payment_token')
                ->index();
        });
    }

    public function down(): void
    {
        // حذف ستون درگاه پرداخت هنگام Rollback.
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_driver']);
            $table->dropColumn('payment_driver');
        });
    }
};
