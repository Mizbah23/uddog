<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    public static function companyRoles(): array
    {
        return [
            'owner' => [UserRole::Admin],
            'manager' => [UserRole::Manager],
            'staff' => [UserRole::Staff],
        ];
    }

    #[DataProvider('companyRoles')]
    public function test_company_user_can_sign_in_with_registered_phone(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'mobile' => '01700000000']);

        $this->postJson('/api/login', ['email' => '01700000000', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_superadmin_cannot_sign_in_with_phone(): void
    {
        User::factory()->superadmin()->create(['mobile' => '01700000000']);

        $this->postJson('/api/login', ['email' => '01700000000', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_superadmin_can_still_sign_in_with_email(): void
    {
        $user = User::factory()->superadmin()->create(['mobile' => '01700000000']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_database_rejects_company_account_using_superadmin_phone(): void
    {
        User::factory()->superadmin()->create(['mobile' => '01700000000']);

        $this->expectException(QueryException::class);

        User::factory()->admin()->create(['mobile' => '01700000000']);
    }

    public function test_company_user_can_still_sign_in_with_email(): void
    {
        $user = User::factory()->staff()->create(['mobile' => '01700000000']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_does_not_sign_in_with_phone(): void
    {
        User::factory()->staff()->create(['mobile' => '01700000000']);

        $this->postJson('/api/login', ['email' => '01700000000', 'password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_sign_in_with_phone(): void
    {
        User::factory()->staff()->create(['mobile' => '01700000000', 'active' => false]);

        $this->postJson('/api/login', ['email' => '01700000000', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_paused_user_cannot_sign_in_with_phone(): void
    {
        User::factory()->staff()->create(['mobile' => '01700000000', 'access_paused' => true]);

        $this->postJson('/api/login', ['email' => '01700000000', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_database_rejects_two_company_accounts_sharing_phone(): void
    {
        User::factory()->admin()->create(['mobile' => '01700000000']);

        $this->expectException(QueryException::class);

        User::factory()->staff()->create(['mobile' => '01700000000']);
    }

    public function test_malformed_email_is_still_rejected(): void
    {
        User::factory()->admin()->create();

        $this->postJson('/api/login', ['email' => 'not-an-email@', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }
}
