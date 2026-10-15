<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
//    public function up(): void
//    {
//        Schema::create('products', function (Blueprint $table) {
//            $table->id();
//            $table->timestamps();
//        });
//    }
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            // اتصال محصول به دسته‌بندی:
            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name'); // نام محصول
            $table->decimal('price', 10, 2)->default(0); // قیمت (اختیاری)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
