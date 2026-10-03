<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_view_and_update_their_own_profile(): void
    {
        $user = User::factory()->create(['mobile' => '01700000000']);

        $this->actingAs($user)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('mobile', '01700000000');

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => 'Updated Account',
            'mobile' => '01800000000',
            'current_password' => 'password',
            'password' => 'new-password-123',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Account')
            ->assertJsonPath('mobile', '01800000000');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
    }

    public function test_profile_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => $user->name,
            'mobile' => '',
            'password' => 'new-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => $user->name,
            'mobile' => '',
            'current_password' => 'incorrect-password',
            'password' => 'new-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)->putJson('/api/profile', [
            'name' => $user->name,
            'mobile' => '',
            'current_password' => 'password',
            'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_only_company_owner_receives_plan_and_remaining_days_in_session(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));
        $company = Organization::factory()->create([
            'plan_name' => 'Growth',
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_ends_at' => '2026-10-06',
        ]);
        $owner = User::factory()->admin()->for($company)->create();
        $staff = User::factory()->staff()->for($company)->create();

        $this->actingAs($owner)->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('subscription.plan_name', 'Growth')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.ends_on', '2026-10-06')
            ->assertJsonPath('subscription.days_remaining', 3)
            ->assertJsonPath('subscription.active', true);

        $this->actingAs($staff)->getJson('/api/session')->assertJsonPath('subscription', null);
    }

    public function test_expiry_date_is_inclusive_then_workspace_pauses_while_profile_remains_available(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:00', 'UTC'));
        $company = Organization::factory()->create([
            'subscription_status' => SubscriptionStatus::Active,
            'subscription_ends_at' => '2026-10-03',
        ]);
        $owner = User::factory()->admin()->for($company)->create();

        $this->actingAs($owner)->getJson('/api/session')
            ->assertJsonPath('subscription.days_remaining', 0)
            ->assertJsonPath('subscription.active', true);

        $this->travelTo(CarbonImmutable::parse('2026-10-04 00:00:00', 'UTC'));

        $this->getJson('/api/session')
            ->assertJsonPath('subscription.days_remaining', -1)
            ->assertJsonPath('subscription.active', false)
            ->assertJsonPath('permissions.use_workspace', false);
        $this->getJson('/api/overview')->assertForbidden()->assertJsonPath('code', 'subscription_inactive');
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('id', $owner->id);
        $this->putJson('/api/profile', ['name' => 'Renewal Contact', 'mobile' => ''])
            ->assertOk()->assertJsonPath('name', 'Renewal Contact');
    }
}
