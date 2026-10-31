<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('ecommerce_enabled')->default(false);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('ecommerce_visible')->default(false);
            $table->text('ecommerce_description')->nullable();
            $table->string('ecommerce_image_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['ecommerce_visible', 'ecommerce_description', 'ecommerce_image_url']);
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('ecommerce_enabled');
        });
    }
};
