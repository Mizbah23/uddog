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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('plan_name')->nullable();
            $table->string('subscription_status')->default('active');
            $table->date('subscription_ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role')->default('manager')->after('password');
            $table->boolean('active')->default(true)->after('role');
            $table->index(['organization_id', 'role']);
        });

        if (DB::table('users')->exists()) {
            $organizationId = DB::table('organizations')->insertGetId([
                'name' => 'Primary Client',
                'plan_name' => 'Owner',
                'subscription_status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $firstUserId = DB::table('users')->min('id');
            DB::table('users')->update(['organization_id' => $organizationId, 'role' => 'admin']);
            DB::table('users')->where('id', $firstUserId)->update(['role' => 'superadmin']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'role']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['role', 'active']);
        });
        Schema::dropIfExists('organizations');
    }
};
