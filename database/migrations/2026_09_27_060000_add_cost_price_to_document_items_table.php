<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            $table->decimal('cost_price', 14, 2)->default(0)->after('unit_price');
        });

        DB::table('document_items')->orderBy('id')->chunkById(500, function ($items) {
            $costs = DB::table('products')
                ->whereIn('id', $items->pluck('product_id'))
                ->pluck('cost_price', 'id');

            foreach ($items as $item) {
                DB::table('document_items')
                    ->where('id', $item->id)
                    ->update(['cost_price' => $costs[$item->product_id] ?? 0]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
