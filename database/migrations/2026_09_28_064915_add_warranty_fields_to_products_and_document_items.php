<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('reorder_level');
        });

        Schema::table('document_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('warranty_months')->nullable()->after('cost_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            $table->dropColumn('warranty_months');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('warranty_months');
        });
    }
};
