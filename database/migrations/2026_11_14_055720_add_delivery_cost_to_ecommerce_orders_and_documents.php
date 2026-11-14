<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ecommerce_orders', 'documents'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->decimal('delivery_cost', 14, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['ecommerce_orders', 'documents'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('delivery_cost');
            });
        }
    }
};
