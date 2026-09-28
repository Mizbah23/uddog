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
        Schema::create('support_impersonations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('impersonated_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['impersonator_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_impersonations');
    }
};
