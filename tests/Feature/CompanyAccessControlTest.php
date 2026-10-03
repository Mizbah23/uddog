<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\SubscriptionStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_pause_and_restore_company_without_changing_its_subscription(): void
    {
        $company = Organization::factory()->create(['plan_name' => 'Growth']);
        $owner = User::factory()->admin()->for($company)->create();
        $staff = User::factory()->staff()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();
        $originalExpiry = $company->subscription_ends_at->toDateString();

        $this->actingAs($superadmin)->putJson("/api/clients/{$company->id}/access", ['paused' => true])
            ->assertOk()
            ->assertJsonPath('access_paused', true)
            ->assertJsonPath('subscription_status', SubscriptionStatus::Active->value);

        $this->assertSame($originalExpiry, $company->fresh()->subscription_ends_at->toDateString());
        $this->actingAs($owner)->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('permissions.use_workspace', false)
            ->assertJsonPath('subscription.access_paused', true);
        $this->getJson('/api/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'company_access_paused');
        $this->actingAs($staff)->getJson('/api/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'company_access_paused');

        $this->actingAs($superadmin)->putJson("/api/clients/{$company->id}/access", ['paused' => false])
            ->assertOk()
            ->assertJsonPath('access_paused', false);
        $this->actingAs($staff->fresh())->getJson('/api/overview')->assertOk();
    }

    public function test_restoring_company_does_not_bypass_expired_subscription(): void
    {
        $company = Organization::factory()->create([
            'subscription_ends_at' => now()->subDay()->toDateString(),
            'access_paused' => true,
        ]);
        $staff = User::factory()->staff()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->putJson("/api/clients/{$company->id}/access", ['paused' => false])
            ->assertOk()
            ->assertJsonPath('subscription_active', false);
        $this->actingAs($staff)->getJson('/api/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_inactive');
    }

    public function test_superadmin_can_pause_one_company_user_without_blocking_other_users(): void
    {
        $company = Organization::factory()->create();
        $owner = User::factory()->admin()->for($company)->create();
        $staff = User::factory()->staff()->for($company)->create();
        $otherStaff = User::factory()->staff()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->putJson("/api/users/{$staff->id}/access", ['paused' => true])
            ->assertOk()
            ->assertJsonPath('access_paused', true)
            ->assertJsonPath('active', true);
        $this->actingAs($staff->fresh())->getJson('/api/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'user_access_paused');
        $this->actingAs($owner)->getJson('/api/overview')->assertOk();
        $this->actingAs($otherStaff)->getJson('/api/overview')->assertOk();

        $this->actingAs($owner)->putJson("/api/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => UserRole::Staff->value,
            'organization_id' => $company->id,
            'active' => true,
            'permissions' => $staff->permissions,
            'branch_ids' => $staff->accessibleBranches()->pluck('branches.id')->all(),
        ])->assertOk();
        $this->assertTrue($staff->fresh()->access_paused);

        $this->actingAs($superadmin)->putJson("/api/users/{$staff->id}/access", ['paused' => false])
            ->assertOk()
            ->assertJsonPath('access_paused', false);
        $this->actingAs($staff->fresh())->getJson('/api/overview')->assertOk();
    }

    public function test_only_superadmins_can_change_platform_access(): void
    {
        $company = Organization::factory()->create();
        $owner = User::factory()->admin()->for($company)->create();
        $staff = User::factory()->staff()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($owner)->putJson("/api/clients/{$company->id}/access", ['paused' => true])->assertForbidden();
        $this->putJson("/api/users/{$staff->id}/access", ['paused' => true])->assertForbidden();
        $this->actingAs($superadmin)->putJson("/api/users/{$superadmin->id}/access", ['paused' => true])->assertNotFound();
        $this->putJson("/api/clients/{$superadmin->organization_id}/access", ['paused' => true])->assertNotFound();
        $this->assertFalse($company->fresh()->access_paused);
        $this->assertFalse($staff->fresh()->access_paused);
    }

    public function test_paused_user_cannot_sign_in_or_keep_an_existing_session(): void
    {
        $staff = User::factory()->staff()->create(['password' => 'password']);
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->putJson("/api/users/{$staff->id}/access", ['paused' => true])->assertOk();
        $this->postJson('/api/login', ['email' => $staff->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->actingAs($staff->fresh())->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('user', null)
            ->assertJsonPath('account_disabled', true);
    }
}
