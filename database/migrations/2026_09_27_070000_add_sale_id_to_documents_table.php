<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('purchase_id')->constrained('documents')->restrictOnDelete();
            $table->index(['organization_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'sale_id']);
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
