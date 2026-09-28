<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
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

    public function test_admin_manages_only_non_superadmin_users_in_their_client(): void
    {
        $admin = User::factory()->admin()->create();
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
