<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sale_payments', 'purchase_payments', 'expenses', 'documents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->index(['organization_id', 'branch_id', 'payment_method', 'created_by'], "cash_{$table}_lookup");
            });
        }
    }

    public function down(): void
    {
        foreach (['sale_payments', 'purchase_payments', 'expenses', 'documents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex("cash_{$table}_lookup");
            });
        }
    }
};
