<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 30);
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
            $table->index(['organization_id', 'branch_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'branch_id']);
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'branch_id']);
        });

        DB::table('organizations')->orderBy('id')->each(function ($organization) {
            $branchId = DB::table('branches')->insertGetId([
                'organization_id' => $organization->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_default' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('documents')->where('organization_id', $organization->id)->update(['branch_id' => $branchId]);
            DB::table('stock_movements')->where('organization_id', $organization->id)->update(['branch_id' => $branchId]);
            DB::table('products')->where('organization_id', $organization->id)->orderBy('id')->each(function ($product) use ($organization, $branchId) {
                DB::table('product_stocks')->insert([
                    'organization_id' => $organization->id,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'quantity_on_hand' => $product->quantity_on_hand,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('branches');
    }
};
