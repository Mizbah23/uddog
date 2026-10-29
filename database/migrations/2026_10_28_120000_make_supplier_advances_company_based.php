<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_advances', function (Blueprint $table) {
            $table->unsignedTinyInteger('expected_installments')->nullable()->change();
        });
        Schema::table('supplier_advance_deliveries', function (Blueprint $table) {
            $table->string('company_name', 255)->nullable()->after('supplier_advance_id');
            $table->foreignId('supplier_advance_id')->nullable()->change();
        });
        DB::table('supplier_advance_deliveries')->whereNotNull('supplier_advance_id')->orderBy('id')->chunkById(500, function ($deliveries): void {
            foreach ($deliveries as $delivery) {
                $companyName = DB::table('supplier_advances')->where('id', $delivery->supplier_advance_id)->value('company_name');
                DB::table('supplier_advance_deliveries')->where('id', $delivery->id)->update(['company_name' => $companyName]);
            }
        });
        Schema::table('supplier_advance_deliveries', function (Blueprint $table) {
            $table->index(['organization_id', 'branch_id', 'company_name'], 'sa_delivery_company_idx');
        });
    }

    public function down(): void
    {
        // Old installment limits and per-advance links cannot be reconstructed from company-level deliveries.
    }
};
