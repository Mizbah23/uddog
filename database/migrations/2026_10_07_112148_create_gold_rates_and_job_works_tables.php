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
        Schema::create('gold_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('metal', 20)->default('gold');
            $table->string('purity', 20);
            $table->decimal('rate_per_gram', 14, 2);
            $table->date('effective_on');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'metal', 'purity', 'effective_on']);
            $table->index(['organization_id', 'branch_id', 'effective_on']);
        });
        Schema::create('gold_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->string('job_type', 20);
            $table->string('status', 20)->default('open');
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gold_purity', 20)->nullable();
            $table->decimal('input_weight', 14, 3)->default(0);
            $table->decimal('output_weight', 14, 3)->default(0);
            $table->decimal('wastage_weight', 14, 3)->default(0);
            $table->decimal('labor_amount', 14, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gold_jobs');
        Schema::dropIfExists('gold_rates');
    }
};
