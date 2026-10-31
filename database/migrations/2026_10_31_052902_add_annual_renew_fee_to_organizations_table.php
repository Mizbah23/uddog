<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->decimal('annual_renew_fee', 12, 2)->nullable();
        });
        foreach (['Starter' => 4000, 'Standard' => 7000, 'Premium' => 12000] as $plan => $fee) {
            DB::table('organizations')->where('plan_name', $plan)->update(['annual_renew_fee' => $fee]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('annual_renew_fee');
        });
    }
};
