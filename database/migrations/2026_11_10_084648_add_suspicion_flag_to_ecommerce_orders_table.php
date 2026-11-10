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
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->timestamp('flagged_at')->nullable();
            $table->string('flag_reason', 255)->nullable();
            $table->index(['organization_id', 'flagged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'flagged_at']);
            $table->dropColumn(['flagged_at', 'flag_reason']);
        });
    }
};
