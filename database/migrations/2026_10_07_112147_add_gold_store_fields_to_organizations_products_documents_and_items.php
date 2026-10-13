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
            $table->boolean('gold_store')->default(false)->after('access_paused');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_gold_item')->default(false)->after('active');
            $table->string('gold_purity', 20)->nullable();
            $table->decimal('gold_gross_weight', 14, 3)->nullable();
            $table->decimal('gold_stone_weight', 14, 3)->default(0);
            $table->decimal('gold_other_weight', 14, 3)->default(0);
            $table->decimal('stone_value', 14, 2)->default(0);
            $table->string('making_charge_mode', 30)->default('per_gram');
            $table->decimal('making_charge', 14, 2)->default(0);
            $table->decimal('wastage_percent', 6, 3)->default(0);
            $table->string('tag_number', 80)->nullable();
            $table->string('hallmark_code', 80)->nullable();
            $table->unique(['organization_id', 'tag_number']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('gold_exchange_weight', 14, 3)->default(0);
            $table->string('gold_exchange_purity', 20)->nullable();
            $table->decimal('gold_exchange_rate', 14, 2)->default(0);
            $table->decimal('gold_exchange_value', 14, 2)->default(0);
        });
        Schema::table('document_items', function (Blueprint $table) {
            $table->decimal('gold_gross_weight', 14, 3)->nullable();
            $table->decimal('gold_net_weight', 14, 3)->nullable();
            $table->string('gold_purity', 20)->nullable();
            $table->decimal('gold_rate', 14, 2)->nullable();
            $table->decimal('making_charge', 14, 2)->default(0);
            $table->decimal('wastage_weight', 14, 3)->default(0);
            $table->decimal('stone_value', 14, 2)->default(0);
            $table->string('tag_number', 80)->nullable();
            $table->string('hallmark_code', 80)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            $table->dropColumn(['gold_gross_weight', 'gold_net_weight', 'gold_purity', 'gold_rate', 'making_charge', 'wastage_weight', 'stone_value', 'tag_number', 'hallmark_code']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['gold_exchange_weight', 'gold_exchange_purity', 'gold_exchange_rate', 'gold_exchange_value']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'tag_number']);
            $table->dropColumn(['is_gold_item', 'gold_purity', 'gold_gross_weight', 'gold_stone_weight', 'gold_other_weight', 'stone_value', 'making_charge_mode', 'making_charge', 'wastage_percent', 'tag_number', 'hallmark_code']);
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('gold_store');
        });
    }
};
