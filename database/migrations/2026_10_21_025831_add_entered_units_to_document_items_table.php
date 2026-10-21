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
        Schema::table('document_items', function (Blueprint $table) {
            $table->string('entered_unit', 30)->nullable();
            $table->unsignedInteger('unit_conversion')->nullable();
            $table->decimal('entered_quantity', 14, 3)->nullable();
            $table->decimal('entered_unit_price', 14, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            $table->dropColumn(['entered_unit', 'unit_conversion', 'entered_quantity', 'entered_unit_price']);
        });
    }
};
