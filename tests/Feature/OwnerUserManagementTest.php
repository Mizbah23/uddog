<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\SupportImpersonation;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\SubscriptionStatus;
use App\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OwnerUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tenantFile = null;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        config(['tenancy.enabled' => false]);
        if ($this->tenantFile && is_file($this->tenantFile)) {
            unlink($this->tenantFile);
        }

        parent::tearDown();
    }

    public function test_owner_can_view_as_active_manager_and_return_without_gaining_extra_permissions(): void
    {
        $owner = User::factory()->admin()->create();
        $manager = User::factory()->for($owner->organization)->create(['permissions' => []]);

        $this->actingAs($owner)->postJson("/api/users/{$manager->id}/impersonate")
            ->assertOk()->assertJsonPath('user.id', $manager->id);
        $this->assertAuthenticatedAs($manager);
        $this->getJson('/api/session')->assertOk()
            ->assertJsonPath('impersonation.impersonator.id', $owner->id)
            ->assertJsonPath('impersonation.impersonator.role', 'admin')
            ->assertJsonPath('permissions.manage_users', false);
        $this->getJson('/api/users')->assertForbidden();

        $this->deleteJson('/api/support-session')->assertOk()->assertJsonPath('user.id', $owner->id);
        $this->assertAuthenticatedAs($owner);
        $this->assertNotNull(SupportImpersonation::query()->firstOrFail()->ended_at);
    }

    public function test_owner_cannot_view_owner_or_other_company_user_or_inactive_user(): void
    {
        $owner = User::factory()->admin()->create();
        $otherOwner = User::factory()->admin()->for($owner->organization)->create();
        $otherCompanyStaff = User::factory()->staff()->create();
        $inactiveStaff = User::factory()->staff()->for($owner->organization)->create(['active' => false]);

        $this->actingAs($owner)->postJson("/api/users/{$otherOwner->id}/impersonate")->assertNotFound();
        $this->postJson("/api/users/{$otherCompanyStaff->id}/impersonate")->assertNotFound();
        $this->postJson("/api/users/{$inactiveStaff->id}/impersonate")
            ->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertSame(0, SupportImpersonation::query()->count());
    }

    public function test_staff_cannot_start_view_as_user_or_delete_an_inactive_colleague(): void
    {
        $organization = Organization::factory()->create();
        $staff = User::factory()->staff()->for($organization)->create();
        $colleague = User::factory()->staff()->for($organization)->create(['active' => false]);

        $this->actingAs($staff)->postJson("/api/users/{$colleague->id}/impersonate")->assertForbidden();
        $this->deleteJson("/api/users/{$colleague->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $colleague->id]);
    }

    public function test_owner_view_as_user_does_not_bypass_an_expired_subscription(): void
    {
        $organization = Organization::factory()->create(['subscription_status' => SubscriptionStatus::Active]);
        $owner = User::factory()->admin()->for($organization)->create();
        $staff = User::factory()->staff()->for($organization)->create();

        $this->actingAs($owner)->postJson("/api/users/{$staff->id}/impersonate")->assertOk();
        $organization->update(['subscription_status' => SubscriptionStatus::Suspended]);
        auth()->user()->unsetRelation('organization');

        $this->getJson('/api/overview')->assertForbidden();
        $this->deleteJson('/api/support-session')->assertOk();
    }

    public function test_view_as_user_session_ends_when_owner_loses_access(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create();

        $this->actingAs($owner)->postJson("/api/users/{$staff->id}/impersonate")->assertOk();
        $owner->update(['active' => false]);

        $this->getJson('/api/session')->assertOk()->assertJsonPath('user', null);
        $this->assertGuest();
        $this->assertNotNull(SupportImpersonation::query()->firstOrFail()->ended_at);
    }

    public function test_owner_can_permanently_delete_only_inactive_user_without_business_history(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create(['active' => false]);
        $otherStaff = User::factory()->staff()->create(['active' => false]);

        $this->actingAs($owner)->deleteJson("/api/users/{$otherStaff->id}")->assertNotFound();
        $this->deleteJson("/api/users/{$staff->id}")->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('users', ['id' => $otherStaff->id]);
    }

    public function test_owner_cannot_delete_active_user_or_user_with_recorded_activity(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create();
        $branch = Branch::query()->where('organization_id', $owner->organization_id)->firstOrFail();

        $this->actingAs($owner)->deleteJson("/api/users/{$staff->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('user');
        $staff->update(['active' => false]);
        DB::table('sales_targets')->insert([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branch->id,
            'user_id' => $staff->id,
            'period' => today()->toDateString(),
            'target_amount' => 100,
            'created_by' => $owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/users/{$staff->id}")
            ->assertUnprocessable()->assertJsonPath('errors.user.0', 'This user is active or has recorded activity and cannot be permanently deleted.');
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_view_as_user_and_hard_delete_work_with_a_separate_company_database(): void
    {
        $company = Organization::factory()->create();
        $owner = User::factory()->admin()->for($company)->create();
        $staff = User::factory()->staff()->for($company)->create();
        $database = 'uddog_test_'.Str::random(16);
        $this->tenantFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'.sqlite';
        touch($this->tenantFile);
        $company->forceFill(['tenant_database' => $database])->save();
        config([
            'tenancy.enabled' => true,
            'tenancy.central_connection' => 'sqlite',
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'admin.test',
            'tenancy.sqlite_directory' => sys_get_temp_dir(),
        ]);

        app(TenantContext::class)->run($company, function () use ($company, $owner, $staff): void {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]));
            $tenant = DB::connection('tenant');
            $tenant->table('organizations')->insert((array) DB::connection('sqlite')->table('organizations')->find($company->id));
            foreach ([$owner, $staff] as $user) {
                $tenant->table('users')->insert([
                    'id' => $user->id,
                    'organization_id' => $company->id,
                    'name' => 'Shadow user',
                    'email' => "shadow-{$user->id}@tenant.invalid",
                    'password' => bcrypt(Str::random(40)),
                    'role' => $user->role->value,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $tenant->table('branches')->insert([
                'organization_id' => $company->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_default' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $base = 'http://'.$company->id.'.test/api/';
        $this->actingAs($owner)->postJson($base.'users/'.$staff->id.'/impersonate')->assertOk();
        $this->getJson($base.'session')->assertJsonPath('impersonation.impersonator.id', $owner->id);
        $this->deleteJson($base.'support-session')->assertOk();

        $staff->update(['active' => false]);
        app(TenantContext::class)->run($company, function () use ($company, $owner, $staff): void {
            $tenant = DB::connection('tenant');
            $tenant->table('sales_targets')->insert([
                'organization_id' => $company->id,
                'branch_id' => $tenant->table('branches')->value('id'),
                'user_id' => $staff->id,
                'period' => today()->toDateString(),
                'target_amount' => 100,
                'created_by' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->deleteJson($base.'users/'.$staff->id)->assertUnprocessable()->assertJsonValidationErrors('user');
        app(TenantContext::class)->run($company, fn () => DB::connection('tenant')->table('sales_targets')->delete());
        $this->deleteJson($base.'users/'.$staff->id)->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
        app(TenantContext::class)->run($company, function () use ($staff): void {
            $this->assertFalse(DB::connection('tenant')->table('users')->where('id', $staff->id)->exists());
        });
    }

    public function test_tenant_user_sync_and_migration_use_central_profile_details(): void
    {
        $company = Organization::factory()->create();
        $owner = User::factory()->admin()->for($company)->create([
            'name' => 'Habibur Rahman',
            'email' => 'habibur@example.com',
        ]);
        $staff = User::factory()->staff()->for($company)->create([
            'name' => 'Rahim Uddin',
            'email' => 'rahim@example.com',
        ]);
        $database = 'uddog_test_'.Str::random(16);
        $this->tenantFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'.sqlite';
        touch($this->tenantFile);
        $company->forceFill(['tenant_database' => $database])->save();
        config([
            'tenancy.enabled' => true,
            'tenancy.central_connection' => 'sqlite',
            'tenancy.sqlite_directory' => sys_get_temp_dir(),
        ]);

        app(TenantContext::class)->run($company, function () use ($company, $owner, $staff): void {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]));
            DB::connection('tenant')->table('organizations')->insert(
                (array) DB::connection('sqlite')->table('organizations')->find($company->id)
            );
            foreach ([$owner, $staff] as $user) {
                DB::connection('tenant')->table('users')->insert([
                    'id' => $user->id,
                    'organization_id' => $company->id,
                    'name' => 'User '.$user->id,
                    'email' => "shadow-{$user->id}@tenant.invalid",
                    'password' => bcrypt(Str::random(40)),
                    'role' => $user->role->value,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        app(TenantProvisioner::class)->syncUserShadow($staff);
        app(TenantContext::class)->run($company, function () use ($owner, $staff): void {
            $shadow = DB::connection('tenant')->table('users')->find($staff->id);
            $this->assertSame('Rahim Uddin', $shadow->name);
            $this->assertSame('rahim@example.com', $shadow->email);
            $this->assertNotSame($staff->password, $shadow->password);

            DB::connection('tenant')->table('users')->where('id', $staff->id)->update([
                'name' => 'User '.$staff->id,
                'email' => "shadow-{$staff->id}@tenant.invalid",
            ]);
            $migration = require database_path('migrations/2026_10_29_050329_sync_tenant_user_names_and_emails.php');
            $migration->up();

            $repairedOwner = DB::connection('tenant')->table('users')->find($owner->id);
            $this->assertSame('Habibur Rahman', $repairedOwner->name);
            $this->assertSame('habibur@example.com', $repairedOwner->email);
            $repaired = DB::connection('tenant')->table('users')->find($staff->id);
            $this->assertSame('Rahim Uddin', $repaired->name);
            $this->assertSame('rahim@example.com', $repaired->email);
        });
    }
}
