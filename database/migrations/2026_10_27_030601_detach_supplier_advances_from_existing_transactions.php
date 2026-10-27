<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_advances') || ! Schema::hasColumn('supplier_advances', 'contact_id')) {
            return;
        }

        Schema::table('supplier_advances', function (Blueprint $table): void {
            $table->string('company_name')->default('Company not recorded');
        });
        DB::table('supplier_advances')->select(['id', 'contact_id'])->orderBy('id')->chunkById(100, function ($advances): void {
            foreach ($advances as $advance) {
                $name = DB::table('contacts')->where('id', $advance->contact_id)->value('name');
                DB::table('supplier_advances')->where('id', $advance->id)->update(['company_name' => $name ?: 'Company not recorded']);
            }
        });

        Schema::table('supplier_advance_deliveries', function (Blueprint $table): void {
            $table->string('product_details')->default('Recorded product delivery');
        });
        DB::table('supplier_advance_deliveries')->select(['id', 'document_id'])->orderBy('id')->chunkById(100, function ($deliveries): void {
            foreach ($deliveries as $delivery) {
                $number = DB::table('documents')->where('id', $delivery->document_id)->value('number');
                DB::table('supplier_advance_deliveries')->where('id', $delivery->id)->update([
                    'product_details' => $number ? 'Recorded delivery '.$number : 'Recorded product delivery',
                ]);
            }
        });

        Schema::table('supplier_advance_deliveries', function (Blueprint $table): void {
            foreach (['organization_id', 'branch_id', 'document_id', 'purchase_payment_id', 'created_by'] as $column) {
                $table->dropForeign([$column]);
            }
        });
        Schema::table('supplier_advance_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['document_id']);
            $table->dropUnique(['purchase_payment_id']);
            $table->dropColumn(['document_id', 'purchase_payment_id']);
        });

        Schema::table('supplier_advances', function (Blueprint $table): void {
            foreach (['organization_id', 'branch_id', 'contact_id', 'created_by'] as $column) {
                $table->dropForeign([$column]);
            }
        });
        Schema::table('supplier_advances', function (Blueprint $table): void {
            $table->dropColumn('contact_id');
        });
    }

    public function down(): void
    {
        // Existing contact and purchase links cannot be reconstructed for new standalone records.
    }
};
