<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
            $table->string('unit')->default('pc')->change();
        });

        DB::table('products')->where('unit', 'pcs')->update(['unit' => 'pc']);
    }

    public function down(): void
    {
        DB::table('products')->whereNull('sku')->orderBy('id')->chunkById(100, function ($products) {
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update(['sku' => 'RESTORED-'.$product->id.'-'.Str::ulid()]);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable(false)->change();
            $table->string('unit')->default('pcs')->change();
        });
    }
};
