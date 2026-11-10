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
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('courier_settings')->nullable();
        });
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->json('courier_booking')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ecommerce_orders', function (Blueprint $table) {
            $table->dropColumn('courier_booking');
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('courier_settings');
        });
    }
};
