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
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
        });

        foreach (['contacts', 'products', 'documents', 'stock_movements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        $organizationId = DB::table('organizations')->min('id');
        if ($organizationId) {
            foreach (['contacts', 'products', 'documents', 'stock_movements'] as $tableName) {
                DB::table($tableName)->update(['organization_id' => $organizationId]);
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['organization_id', 'sku']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->index(['organization_id', 'type', 'document_date']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['organization_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'product_id']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'type', 'document_date']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'sku']);
            $table->unique('sku');
        });

        foreach (['stock_movements', 'documents', 'products', 'contacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('organization_id');
            });
        }
    }
};
