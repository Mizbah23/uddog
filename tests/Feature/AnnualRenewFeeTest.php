<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AnnualRenewFeeTest extends TestCase
{
    use RefreshDatabase;

    private function companyPayload(string $plan = 'Standard'): array
    {
        return ['name' => 'Renewal Store', 'plan_name' => $plan, 'subscription_status' => 'active', 'owner_name' => 'Store Owner', 'owner_email' => 'renewal-owner@example.test', 'owner_password' => 'Password123'];
    }

    #[TestWith(['Starter', '4000.00'])]
    #[TestWith(['Standard', '7000.00'])]
    #[TestWith(['Premium', '12000.00'])]
    public function test_new_company_defaults_annual_fee_from_selected_package(string $plan, string $fee): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $response = $this->actingAs($superadmin)->postJson('/api/clients', $this->companyPayload($plan))->assertCreated()->assertJsonPath('annual_renew_fee', $fee);
        $this->assertDatabaseHas('organizations', ['id' => $response->json('id'), 'annual_renew_fee' => $fee]);
    }

    public function test_package_changes_update_default_fee_and_custom_fee_is_preserved_on_unrelated_edits(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $company = $this->actingAs($superadmin)->postJson('/api/clients', $this->companyPayload('Starter'))->assertCreated()->json();
        $payload = [...$this->companyPayload('Premium'), 'owner_active' => true];
        unset($payload['owner_password']);
        $this->putJson('/api/clients/'.$company['id'], $payload)->assertOk()->assertJsonPath('annual_renew_fee', '12000.00');
        $this->putJson('/api/clients/'.$company['id'], [...$payload, 'annual_renew_fee' => '13500.00'])->assertOk()->assertJsonPath('annual_renew_fee', '13500.00');
        $this->putJson('/api/clients/'.$company['id'], $payload)->assertOk()->assertJsonPath('annual_renew_fee', '13500.00');
    }

    #[TestWith([-1])]
    #[TestWith(['100.001'])]
    #[TestWith(['invalid'])]
    public function test_invalid_annual_fee_is_rejected(string|int $fee): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin)->postJson('/api/clients', [...$this->companyPayload(), 'annual_renew_fee' => $fee])->assertUnprocessable()->assertJsonValidationErrors('annual_renew_fee');
        $this->assertDatabaseMissing('organizations', ['name' => 'Renewal Store']);
    }

    public function test_expired_owner_session_exposes_saved_fee_and_blocks_workspace_access(): void
    {
        $company = Organization::factory()->create(['plan_name' => 'Standard', 'annual_renew_fee' => '6500.00', 'subscription_ends_at' => now('Asia/Dhaka')->subDay()->toDateString()]);
        $owner = User::factory()->admin()->for($company)->create();
        $this->actingAs($owner)->getJson('/api/session')->assertOk()->assertJsonPath('subscription.annual_renew_fee', '6500.00')
            ->assertJsonPath('subscription.expired', true)->assertJsonPath('permissions.use_workspace', false);
        $this->getJson('/api/overview')->assertForbidden()->assertJsonPath('code', 'subscription_inactive');
    }

    public function test_validity_expires_at_bangladesh_midnight_and_is_valid_through_the_selected_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 18:01:00', 'UTC'));
        $company = Organization::factory()->create(['plan_name' => 'Premium', 'subscription_ends_at' => '2026-10-31']);
        $owner = User::factory()->admin()->for($company)->create();
        $this->actingAs($owner)->getJson('/api/session')->assertOk()->assertJsonPath('subscription.expired', true)
            ->assertJsonPath('subscription.days_remaining', -1)->assertJsonPath('subscription.annual_renew_fee', '12000.00');
        $company->update(['subscription_ends_at' => '2026-11-01']);
        $this->actingAs($owner->fresh())->getJson('/api/session')->assertOk()->assertJsonPath('subscription.expired', false)
            ->assertJsonPath('subscription.days_remaining', 0)->assertJsonPath('permissions.use_workspace', true);
    }

    public function test_renewing_subscription_clears_expiry_without_changing_the_agreed_annual_fee(): void
    {
        $company = Organization::factory()->create(['plan_name' => 'Standard', 'annual_renew_fee' => '6800.00', 'subscription_ends_at' => now('Asia/Dhaka')->subDay()->toDateString()]);
        $owner = User::factory()->admin()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();
        $this->actingAs($superadmin)->postJson('/api/clients/'.$company->id.'/renew', ['months' => 12])->assertOk()->assertJsonPath('annual_renew_fee', '6800.00');
        $this->actingAs($owner->fresh())->getJson('/api/session')->assertOk()->assertJsonPath('subscription.expired', false)->assertJsonPath('permissions.use_workspace', true);
    }

    public function test_owner_cannot_change_the_company_annual_fee(): void
    {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner)->putJson('/api/clients/'.$owner->organization_id, [...$this->companyPayload(), 'owner_active' => true, 'annual_renew_fee' => 1])->assertForbidden();
        $this->assertNull($owner->organization->getRawOriginal('annual_renew_fee'));
    }
}
