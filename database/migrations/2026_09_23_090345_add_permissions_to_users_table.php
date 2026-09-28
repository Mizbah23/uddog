<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('active');
        });

        DB::table('users')->where('role', 'manager')->update([
            'permissions' => json_encode([
                'dashboard', 'products', 'contacts', 'purchases', 'sales', 'resales',
                'purchase_returns', 'inventory', 'stock_adjustments',
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
