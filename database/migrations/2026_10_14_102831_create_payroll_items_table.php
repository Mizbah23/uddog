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
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_member_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->unique()->constrained()->restrictOnDelete();
            $table->string('staff_name', 150);
            $table->string('job_title', 150);
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['payroll_run_id', 'staff_member_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
