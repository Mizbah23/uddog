<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_administrator_can_use_an_eight_character_password(): void
    {
        $this->postJson('/api/setup', [
            'name' => 'Example Admin',
            'email' => 'admin@example.test',
            'password' => 'Abc12345',
        ])->assertCreated()->assertJsonPath('user.email', 'admin@example.test');

        $this->assertSame(1, User::query()->count());
        $this->assertSame(UserRole::Superadmin, User::query()->first()->role);
        $this->assertNotNull(User::query()->first()->organization_id);
    }

    public function test_seven_character_password_is_rejected(): void
    {
        $this->postJson('/api/setup', [
            'name' => 'Example Admin',
            'email' => 'admin@example.test',
            'password' => 'Abc1234',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame(0, User::query()->count());
    }
}
