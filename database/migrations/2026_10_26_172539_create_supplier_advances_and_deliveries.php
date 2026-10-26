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
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->unsignedTinyInteger('expected_installments');
            $table->date('advance_date');
            $table->string('payment_method', 20);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'advance_date']);
        });
        Schema::create('supplier_advance_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_advance_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->unique()->constrained('documents')->restrictOnDelete();
            $table->foreignId('purchase_payment_id')->unique()->constrained('purchase_payments')->restrictOnDelete();
            $table->string('installment_code', 100);
            $table->decimal('amount', 14, 2);
            $table->date('delivery_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['supplier_advance_id', 'installment_code']);
            $table->index(['organization_id', 'branch_id', 'delivery_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_advance_deliveries');
        Schema::dropIfExists('supplier_advances');
    }
};
