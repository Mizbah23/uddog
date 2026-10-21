<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_damages', 'purchase_id')) {
            Schema::table('product_damages', function (Blueprint $table) {
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('purchase_id')->constrained('documents')->restrictOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->decimal('quantity', 14, 3);
                $table->date('damaged_on');
                $table->string('reason', 150);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            });
        }

        if (! Schema::hasIndex('product_damages', 'damage_branch_product_date_idx')) {
            Schema::table('product_damages', function (Blueprint $table) {
                $table->index(['organization_id', 'branch_id', 'product_id', 'damaged_on'], 'damage_branch_product_date_idx');
            });
        }
        if (! Schema::hasIndex('product_damages', 'damage_purchase_product_idx')) {
            Schema::table('product_damages', function (Blueprint $table) {
                $table->index(['purchase_id', 'product_id'], 'damage_purchase_product_idx');
            });
        }

        if (! Schema::hasColumn('stock_movements', 'product_damage_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->foreignId('product_damage_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // The original create migration owns these columns on fresh installations.
    }
};
