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
        Schema::table('contacts', function (Blueprint $table) {
            $table->boolean('is_default')->default(false);
        });

        $organizationIds = [];
        DB::table('contacts')
            ->where('name', 'General Customer')
            ->whereIn('type', ['customer', 'both'])
            ->orderBy('id')
            ->select(['id', 'organization_id'])
            ->chunkById(500, function ($contacts) use (&$organizationIds): void {
                foreach ($contacts as $contact) {
                    if (isset($organizationIds[$contact->organization_id])) {
                        continue;
                    }

                    DB::table('contacts')->where('id', $contact->id)->update(['is_default' => true]);
                    $organizationIds[$contact->organization_id] = true;
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
