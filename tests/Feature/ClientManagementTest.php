<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SupportImpersonation;
use App\Models\User;
use App\Services\DefaultCustomer;
use App\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_company_has_one_default_walk_in_customer(): void
    {
        $superadmin = User::factory()->superadmin()->create();

        $companyId = $this->actingAs($superadmin)->postJson('/api/clients', [
            'name' => 'New Store',
            'subscription_status' => SubscriptionStatus::Trial->value,
            'owner_name' => 'Store Owner',
            'owner_email' => 'store-owner@example.test',
            'owner_password' => 'Password123',
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('contacts', [
            'organization_id' => $companyId,
            'name' => DefaultCustomer::NAME,
            'type' => 'customer',
            'active' => true,
        ]);

        $company = Organization::query()->findOrFail($companyId);
        app(DefaultCustomer::class)->ensure(DB::connection(config('tenancy.central_connection')), $company);
        $this->assertSame(1, Contact::query()->where('organization_id', $companyId)->count());
    }

    public function test_company_owner_can_be_created_with_phone_only_and_sign_in(): void
    {
        $superadmin = User::factory()->superadmin()->create();

        $response = $this->actingAs($superadmin)->postJson('/api/clients', [
            'name' => 'Phone Owner Store',
            'subscription_status' => SubscriptionStatus::Trial->value,
            'owner_name' => 'Phone Owner',
            'owner_email' => '',
            'owner_mobile' => '01712345678',
            'owner_password' => 'Password123',
        ])->assertCreated()
            ->assertJsonPath('admins.0.email', null)
            ->assertJsonPath('admins.0.mobile', '01712345678');

        $owner = User::query()->where('organization_id', $response->json('id'))->firstOrFail();
        $this->assertNull($owner->email);

        $this->postJson('/api/logout');
        $this->postJson('/api/login', ['email' => '01712345678', 'password' => 'Password123'])
            ->assertOk()->assertJsonPath('user.id', $owner->id);
    }

    public function test_company_owner_requires_email_or_phone_and_unique_phone(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        User::factory()->create(['mobile' => '01712345678']);
        $payload = [
            'name' => 'Another Store',
            'subscription_status' => SubscriptionStatus::Trial->value,
            'owner_name' => 'Another Owner',
            'owner_password' => 'Password123',
        ];

        $this->actingAs($superadmin)->postJson('/api/clients', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['owner_email', 'owner_mobile']);
        $this->postJson('/api/clients', [...$payload, 'owner_mobile' => '01712345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_mobile');
        $this->assertDatabaseMissing('organizations', ['name' => 'Another Store']);
    }

    public function test_company_owner_can_switch_from_email_to_phone(): void
    {
        $company = Organization::factory()->create();
        $owner = User::factory()->admin()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->putJson("/api/clients/{$company->id}", [
            'name' => $company->name,
            'subscription_status' => SubscriptionStatus::Active->value,
            'owner_name' => $owner->name,
            'owner_email' => '',
            'owner_mobile' => '01812345678',
            'owner_active' => true,
        ])->assertOk()
            ->assertJsonPath('admins.0.email', null)
            ->assertJsonPath('admins.0.mobile', '01812345678');

        $owner->refresh();
        $this->assertNull($owner->email);
        $this->assertSame('01812345678', $owner->mobile);
    }

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
            'gold_store' => true,
            'notes' => 'Priority support',
            'owner_name' => 'Updated Owner',
            'owner_email' => 'updated-owner@example.test',
            'owner_password' => 'NewPass123',
            'owner_active' => false,
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Company')
            ->assertJsonPath('gold_store', true)
            ->assertJsonPath('admins.0.name', 'Updated Owner')
            ->assertJsonPath('admins.0.active', false);

        $owner->refresh();
        $this->assertSame('updated-owner@example.test', $owner->email);
        $this->assertFalse($owner->active);
        $this->assertTrue(Hash::check('NewPass123', $owner->password));
    }

    public function test_company_subdomains_are_normalized_unique_and_optional(): void
    {
        $company = Organization::factory()->create();
        $anotherCompany = Organization::factory()->create(['subdomain' => 'taken']);
        $owner = User::factory()->admin()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();
        $payload = [
            'name' => $company->name,
            'subscription_status' => SubscriptionStatus::Active->value,
            'owner_name' => $owner->name,
            'owner_email' => $owner->email,
            'owner_active' => true,
        ];

        $this->actingAs($superadmin)->putJson("/api/clients/{$company->id}", [...$payload, 'subdomain' => ' Demo '])
            ->assertOk()->assertJsonPath('subdomain', 'demo');
        $this->putJson("/api/clients/{$company->id}", [...$payload, 'subdomain' => 'taken'])
            ->assertUnprocessable()->assertJsonValidationErrors('subdomain');
        $this->putJson("/api/clients/{$company->id}", [...$payload, 'subdomain' => '2'])
            ->assertUnprocessable()->assertJsonValidationErrors('subdomain');
        $this->putJson("/api/clients/{$company->id}", [...$payload, 'subdomain' => 'www'])
            ->assertUnprocessable()->assertJsonValidationErrors('subdomain');
        $this->putJson("/api/clients/{$company->id}", [...$payload, 'subdomain' => ''])
            ->assertOk()->assertJsonPath('subdomain', null);

        $this->assertSame('taken', $anotherCompany->fresh()->subdomain);
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
