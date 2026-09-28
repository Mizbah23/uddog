<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SupportImpersonation;
use App\Models\User;
use App\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_list_excludes_organizations_assigned_to_a_superadmin(): void
    {
        $systemOrganization = Organization::factory()->create(['name' => 'Primary Client']);
        $superadmin = User::factory()->superadmin()->for($systemOrganization)->create();
        $client = Organization::factory()->create(['name' => 'Customer Company']);
        User::factory()->admin()->for($client)->create();

        $this->actingAs($superadmin)->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $client->id)
            ->assertJsonMissing(['name' => 'Primary Client']);
    }

    public function test_superadmin_can_view_complete_company_details(): void
    {
        $company = Organization::factory()->create();
        $owner = User::factory()->admin()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();
        Product::create([
            'organization_id' => $company->id,
            'name' => 'Detail product',
            'unit' => 'pc',
            'cost_price' => 10,
            'sale_price' => 12,
            'reorder_level' => 1,
        ]);
        Contact::create(['organization_id' => $company->id, 'name' => 'Detail contact', 'type' => 'both']);
        SupportImpersonation::create([
            'impersonator_id' => $superadmin->id,
            'impersonated_user_id' => $owner->id,
            'organization_id' => $company->id,
            'ended_at' => now(),
        ]);

        $this->actingAs($superadmin)->getJson("/api/clients/{$company->id}")
            ->assertOk()
            ->assertJsonPath('products_count', 1)
            ->assertJsonPath('contacts_count', 1)
            ->assertJsonPath('users_count', 1)
            ->assertJsonPath('users.0.id', $owner->id)
            ->assertJsonPath('support_impersonations.0.impersonator.id', $superadmin->id);
    }

    public function test_superadmin_can_update_company_and_owner_account(): void
    {
        $company = Organization::factory()->create();
        $owner = User::factory()->admin()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->putJson("/api/clients/{$company->id}", [
            'name' => 'Updated Company',
            'plan_name' => 'Growth',
            'subscription_status' => SubscriptionStatus::Active->value,
            'subscription_ends_at' => '2027-09-27',
            'notes' => 'Priority support',
            'owner_name' => 'Updated Owner',
            'owner_email' => 'updated-owner@example.test',
            'owner_password' => 'NewPass123',
            'owner_active' => false,
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Company')
            ->assertJsonPath('admins.0.name', 'Updated Owner')
            ->assertJsonPath('admins.0.active', false);

        $owner->refresh();
        $this->assertSame('updated-owner@example.test', $owner->email);
        $this->assertFalse($owner->active);
        $this->assertTrue(Hash::check('NewPass123', $owner->password));
    }

    public function test_superadmin_can_create_a_missing_owner_while_editing_company(): void
    {
        $company = Organization::factory()->create();
        $superadmin = User::factory()->superadmin()->create();
        $payload = [
            'name' => $company->name,
            'plan_name' => $company->plan_name,
            'subscription_status' => SubscriptionStatus::Active->value,
            'subscription_ends_at' => now()->addYear()->toDateString(),
            'notes' => null,
            'owner_name' => 'Assigned Owner',
            'owner_email' => 'assigned-owner@example.test',
            'owner_active' => true,
        ];

        $this->actingAs($superadmin)->putJson("/api/clients/{$company->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('owner_password');

        $this->putJson("/api/clients/{$company->id}", [...$payload, 'owner_password' => 'Owner123'])
            ->assertOk()
            ->assertJsonPath('admins.0.email', 'assigned-owner@example.test');
    }

    public function test_renewal_extends_current_validity_and_reactivates_subscription(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-27'));
        $company = Organization::factory()->create([
            'subscription_status' => SubscriptionStatus::Suspended,
            'subscription_ends_at' => '2026-10-15',
        ]);
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->postJson("/api/clients/{$company->id}/renew", ['months' => 3])
            ->assertOk()
            ->assertJsonPath('subscription_status', SubscriptionStatus::Active->value)
            ->assertJsonPath('subscription_ends_at', '2027-01-15T00:00:00.000000Z');

        $this->postJson("/api/clients/{$company->id}/renew", ['months' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('months');
    }

    public function test_non_superadmin_cannot_read_company_details_or_renew(): void
    {
        $manager = User::factory()->create();
        $company = $manager->organization;

        $this->actingAs($manager)->getJson("/api/clients/{$company->id}")->assertForbidden();
        $this->postJson("/api/clients/{$company->id}/renew", ['months' => 1])->assertForbidden();
    }
}
