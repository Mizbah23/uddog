<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('sale_channel', 20)->default('standard')->after('type');
            $table->string('payment_method', 20)->nullable()->after('total');
            $table->decimal('amount_paid', 14, 2)->default(0)->after('payment_method');
            $table->index(['organization_id', 'sale_channel']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'sale_channel']);
            $table->dropColumn(['sale_channel', 'payment_method', 'amount_paid']);
        });
    }
};
