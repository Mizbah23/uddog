<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('interest_rate', 5, 2)->default(0)->after('tax');
            $table->decimal('interest_amount', 14, 2)->default(0)->after('interest_rate');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['interest_rate', 'interest_amount']);
        });
    }
};
