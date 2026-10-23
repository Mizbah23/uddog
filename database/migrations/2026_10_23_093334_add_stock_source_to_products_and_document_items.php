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
            $table->foreignId('stock_source_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->decimal('stock_source_quantity', 14, 3)->default(1);
        });
        Schema::table('document_items', function (Blueprint $table) {
            $table->foreignId('stock_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->decimal('stock_quantity', 14, 3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_product_id');
            $table->dropColumn('stock_quantity');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_source_product_id');
            $table->dropColumn('stock_source_quantity');
        });
    }
};
