<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Permission;
use App\SubscriptionStatus;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_cannot_manage_users_or_clients(): void
    {
        $manager = User::factory()->create();

        $this->actingAs($manager)->getJson('/api/users')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/clients')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/overview')->assertOk();
    }

    public function test_dashboard_financial_figures_are_visible_only_to_the_company_owner(): void
    {
        $owner = User::factory()->admin()->create();
        $manager = User::factory()->for($owner->organization)->create();
        $staff = User::factory()->staff()->for($owner->organization)->create();
        $branch = Branch::query()->where('organization_id', $owner->organization_id)->where('is_default', true)->firstOrFail();
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        Product::create([
            'organization_id' => $owner->organization_id,
            'name' => 'Low stock product',
            'unit' => 'pc',
            'cost_price' => 10,
            'sale_price' => 20,
            'quantity_on_hand' => 5,
            'reorder_level' => 5,
        ]);
        Document::create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branch->id,
            'number' => 'SAL-DASHBOARD-1',
            'type' => 'sale',
            'contact_id' => $customer->id,
            'document_date' => today(),
            'subtotal' => 20,
            'total' => 20,
            'amount_paid' => 0,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)->getJson('/api/overview')
            ->assertOk()
            ->assertJsonPath('sales_total', 20)
            ->assertJsonPath('stock_value', 50)
            ->assertJsonPath('recent_documents.0.total', '20.00');

        foreach ([$manager, $staff] as $employee) {
            $overview = $this->actingAs($employee)->getJson('/api/overview')
                ->assertOk()
                ->assertJsonPath('products', 1)
                ->assertJsonPath('low_stock', 1)
                ->assertJsonPath('recent_documents.0.number', 'SAL-DASHBOARD-1')
                ->json();

            foreach (['stock_value', 'sales_total', 'purchase_total', 'monthly_totals', 'collection_alerts', 'supplier_due_list', 'customer_due_list', 'overdue_customers'] as $field) {
                $this->assertArrayNotHasKey($field, $overview);
            }
            $this->assertArrayNotHasKey('total', $overview['recent_documents'][0]);
            $this->assertArrayNotHasKey('cost_price', $overview['low_stock_products'][0]);
            $this->assertArrayNotHasKey('sale_price', $overview['low_stock_products'][0]);
        }
    }

    public function test_admin_manages_only_non_superadmin_users_in_their_client(): void
    {
        $admin = User::factory()->admin()->create();
        $mainBranch = Branch::query()->where('organization_id', $admin->organization_id)->where('is_default', true)->firstOrFail();
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->for($otherOrganization)->create();

        $this->actingAs($admin)->getJson('/api/users')
            ->assertOk()
            ->assertJsonMissing(['id' => $otherUser->id]);

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Client Manager',
            'email' => 'client-manager@example.test',
            'password' => 'password',
            'role' => UserRole::Manager->value,
            'organization_id' => $otherOrganization->id,
            'active' => true,
            'branch_ids' => [$mainBranch->id],
        ])->assertCreated()->assertJsonPath('organization_id', $admin->organization_id);

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Forbidden Superadmin',
            'email' => 'forbidden@example.test',
            'password' => 'password',
            'role' => UserRole::Superadmin->value,
            'active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->actingAs($admin)->putJson("/api/users/{$otherUser->id}", [
            'name' => $otherUser->name,
            'email' => $otherUser->email,
            'password' => '',
            'role' => UserRole::Manager->value,
            'organization_id' => $otherOrganization->id,
            'active' => true,
        ])->assertNotFound();
    }

    public function test_superadmin_can_manage_clients_and_users(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $client = $this->actingAs($superadmin)->postJson('/api/clients', [
            'name' => 'New Subscriber',
            'plan_name' => 'Growth',
            'subscription_status' => SubscriptionStatus::Trial->value,
            'subscription_ends_at' => now()->addMonth()->toDateString(),
            'owner_name' => 'New Subscriber Owner',
            'owner_email' => 'owner@example.test',
            'owner_password' => 'password',
        ])->assertCreated()->json();

        $this->actingAs($superadmin)->postJson('/api/users', [
            'name' => 'Subscriber Admin',
            'email' => 'subscriber@example.test',
            'password' => 'password',
            'role' => UserRole::Admin->value,
            'organization_id' => $client['id'],
            'active' => true,
        ])->assertCreated()->assertJsonPath('organization_id', $client['id']);
    }

    public function test_superadmin_can_limit_owner_modules_and_reports(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $organization = Organization::factory()->create();

        $ownerId = $this->actingAs($superadmin)->postJson('/api/users', [
            'name' => 'Limited Owner',
            'email' => 'limited-owner@example.test',
            'mobile' => '01712345678',
            'password' => 'Password123',
            'role' => UserRole::Admin->value,
            'organization_id' => $organization->id,
            'active' => true,
            'permissions' => [Permission::Sales->value, Permission::Products->value],
            'report_permissions' => ['sales'],
        ])->assertCreated()
            ->assertJsonPath('mobile', '01712345678')
            ->assertJsonPath('permissions', [Permission::Sales->value, Permission::Products->value])
            ->assertJsonPath('report_permissions', ['sales'])
            ->json('id');

        $owner = User::query()->findOrFail($ownerId);
        $this->actingAs($owner)->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('permissions.reports', ['sales']);
        $this->getJson('/api/reports?report=sales')->assertOk();
        $this->getJson('/api/reports?report=stock')->assertForbidden();
        $this->getJson('/api/expenses')->assertForbidden();

        $this->actingAs($superadmin)->putJson("/api/users/{$ownerId}", [
            'name' => 'Limited Owner',
            'email' => 'limited-owner@example.test',
            'mobile' => '01712345678',
            'role' => UserRole::Admin->value,
            'organization_id' => $organization->id,
            'active' => true,
            'permissions' => [Permission::Sales->value, Permission::Products->value],
            'report_permissions' => [],
        ])->assertOk()->assertJsonPath('report_permissions', []);
        $this->actingAs($owner->fresh())->getJson('/api/reports?report=sales')->assertForbidden();
    }

    public function test_user_accounts_accept_phone_without_email_but_superadmin_requires_email(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $organization = Organization::factory()->create();
        $payload = [
            'name' => 'Phone User',
            'email' => '',
            'mobile' => '01812345678',
            'password' => 'Password123',
            'role' => UserRole::Admin->value,
            'organization_id' => $organization->id,
            'active' => true,
        ];

        $ownerId = $this->actingAs($superadmin)->postJson('/api/users', $payload)
            ->assertCreated()->assertJsonPath('email', null)->json('id');
        $this->postJson('/api/users', [...$payload, 'name' => 'Duplicate Phone'])
            ->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->postJson('/api/users', [...$payload, 'name' => 'No Contact', 'mobile' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile']);
        $this->postJson('/api/users', [...$payload, 'role' => UserRole::Superadmin->value, 'mobile' => '01912345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/logout');
        $this->postJson('/api/login', ['email' => '01812345678', 'password' => 'Password123'])
            ->assertOk()->assertJsonPath('user.id', $ownerId);
    }

    public function test_inactive_subscription_blocks_client_workspace_but_not_superadmin_support(): void
    {
        $organization = Organization::factory()->create(['subscription_status' => SubscriptionStatus::Suspended]);
        $manager = User::factory()->for($organization)->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($manager)->getJson('/api/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_inactive');
        $this->actingAs($superadmin)->getJson('/api/clients')->assertOk();
    }

    public function test_inactive_user_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'password',
            'active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.test',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
