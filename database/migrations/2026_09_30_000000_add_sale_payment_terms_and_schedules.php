<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('payment_type', 20)->nullable()->after('payment_method');
            $table->decimal('down_payment', 14, 2)->default(0)->after('amount_paid');
            $table->unsignedSmallInteger('installment_count')->nullable()->after('down_payment');
            $table->string('installment_frequency', 20)->nullable()->after('installment_count');
            $table->date('first_installment_date')->nullable()->after('installment_frequency');
            $table->index(['organization_id', 'payment_type']);
        });

        Schema::create('sale_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['document_id', 'sequence']);
            $table->index(['organization_id', 'branch_id', 'due_date']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_installment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 20);
            $table->string('kind', 20)->default('payment');
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['document_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_installments');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'payment_type']);
            $table->dropColumn(['payment_type', 'down_payment', 'installment_count', 'installment_frequency', 'first_installment_date']);
        });
    }
};
