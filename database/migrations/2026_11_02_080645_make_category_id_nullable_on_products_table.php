<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // حذف foreign key فعلی (restrictOnDelete)
            $table->dropForeign(['category_id']);

            // nullable کردن ستون
            $table->foreignId('category_id')
                ->nullable()
                ->change();

            // foreign key جدید با nullOnDelete
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['category_id']);

            $table->foreignId('category_id')
                ->nullable(false)
                ->change();

            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->restrictOnDelete();
        });
    }
};
