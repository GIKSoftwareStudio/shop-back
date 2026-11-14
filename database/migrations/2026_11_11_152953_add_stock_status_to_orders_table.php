<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('stock_status', 30)
                ->default('legacy')
                ->index();
        });

        DB::table('orders')
            ->whereIn('status', ['pending', 'paid', 'shipped'])
            ->update([
                'stock_status' => 'legacy_deducted',
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['stock_status']);
            $table->dropColumn('stock_status');
        });
    }
};
