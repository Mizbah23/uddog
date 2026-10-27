<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_advances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('company_name', 255);
            $table->decimal('amount', 14, 2);
            $table->unsignedTinyInteger('expected_installments');
            $table->date('advance_date');
            $table->string('payment_method', 20);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'advance_date'], 'sa_org_branch_date_idx');
        });
        Schema::create('supplier_advance_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('supplier_advance_id')->constrained()->restrictOnDelete();
            $table->string('installment_code', 100);
            $table->string('product_details', 255);
            $table->decimal('amount', 14, 2);
            $table->date('delivery_date');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['supplier_advance_id', 'installment_code'], 'sa_delivery_code_unique');
            $table->index(['organization_id', 'branch_id', 'delivery_date'], 'sa_delivery_scope_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_advance_deliveries');
        Schema::dropIfExists('supplier_advances');
    }
};
