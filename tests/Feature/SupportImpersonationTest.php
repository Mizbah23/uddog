<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\SupportImpersonation;
use App\Models\User;
use App\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_support_a_company_as_its_owner_and_return(): void
    {
        $organization = Organization::factory()->create([
            'subscription_status' => SubscriptionStatus::Suspended,
        ]);
        $owner = User::factory()->admin()->for($organization)->create();
        $superadmin = User::factory()->superadmin()->create();
        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Client product',
            'unit' => 'pc',
            'cost_price' => 10,
            'sale_price' => 12,
            'quantity_on_hand' => 3,
            'reorder_level' => 1,
        ]);

        $this->actingAs($superadmin)->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('permissions.use_workspace', false)
            ->assertJsonPath('permissions.manage_clients', true);
        $this->getJson('/api/overview')->assertForbidden();

        $this->actingAs($superadmin)
            ->postJson("/api/clients/{$organization->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('user.id', $owner->id);

        $this->assertAuthenticatedAs($owner);
        $this->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('impersonation.impersonator.id', $superadmin->id)
            ->assertJsonPath('impersonation.organization.id', $organization->id)
            ->assertJsonPath('permissions.use_workspace', true);
        $this->getJson('/api/overview')
            ->assertOk()
            ->assertJsonPath('products', 1);

        $this->deleteJson('/api/support-session')
            ->assertOk()
            ->assertJsonPath('user.id', $superadmin->id);

        $this->assertAuthenticatedAs($superadmin);
        $this->assertNotNull(SupportImpersonation::query()->firstOrFail()->ended_at);
    }

    public function test_non_superadmin_cannot_start_a_support_session(): void
    {
        $organization = Organization::factory()->create();
        User::factory()->admin()->for($organization)->create();
        $manager = User::factory()->for($organization)->create();

        $this->actingAs($manager)
            ->postJson("/api/clients/{$organization->id}/impersonate")
            ->assertForbidden();

        $this->assertSame(0, SupportImpersonation::query()->count());
    }

    public function test_company_requires_an_active_owner_for_support_view(): void
    {
        $organization = Organization::factory()->create();
        User::factory()->admin()->for($organization)->create(['active' => false]);
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)
            ->postJson("/api/clients/{$organization->id}/impersonate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client');
    }
}
