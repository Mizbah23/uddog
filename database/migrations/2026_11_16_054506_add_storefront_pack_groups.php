<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('ecommerce_pack_sizes')->default(false);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->string('ecommerce_pack_group', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('ecommerce_pack_group');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('ecommerce_pack_sizes');
        });
    }
};
