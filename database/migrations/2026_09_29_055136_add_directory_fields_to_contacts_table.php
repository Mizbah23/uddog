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
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('company')->nullable()->after('name');
            $table->string('area')->nullable()->after('address');
            $table->string('category')->nullable()->after('area');
            $table->decimal('credit_limit', 14, 2)->default(0)->after('category');
            $table->boolean('active')->default(true)->after('credit_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['company', 'area', 'category', 'credit_limit', 'active']);
        });
    }
};
