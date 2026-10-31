<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sale_invoices')->default(0);
            $table->timestamps();
        });
        DB::table('organizations')->orderBy('id')->chunkById(200, function ($organizations): void {
            foreach ($organizations as $organization) {
                DB::table('package_usages')->insert([
                    'organization_id' => $organization->id,
                    'sale_invoices' => DB::table('documents')->where('organization_id', $organization->id)->whereIn('type', ['sale', 'resale'])->count(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_usages');
    }
};
