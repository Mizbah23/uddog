<?php

namespace Tests\Feature;

use App\Models\User;
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
}
