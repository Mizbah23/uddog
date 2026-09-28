<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('barcode')->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'category_id']);
        });

        DB::table('products')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->orderBy('id')
            ->each(function ($product) {
                $categoryId = DB::table('categories')->where([
                    'organization_id' => $product->organization_id,
                    'name' => $product->category,
                ])->value('id');

                if (! $categoryId) {
                    $categoryId = DB::table('categories')->insertGetId([
                        'organization_id' => $product->organization_id,
                        'name' => $product->category,
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('products')->where('id', $product->id)->update(['category_id' => $categoryId]);
            });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('category')->nullable()->after('name');
        });

        DB::table('products')->orderBy('id')->each(function ($product) {
            $name = $product->category_id ? DB::table('categories')->where('id', $product->category_id)->value('name') : null;
            DB::table('products')->where('id', $product->id)->update(['category' => $name]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'category_id']);
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('categories');
    }
};
