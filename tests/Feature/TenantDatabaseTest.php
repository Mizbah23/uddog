<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class TenantDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tenantFiles = [];

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        config(['tenancy.enabled' => false]);
        foreach ($this->tenantFiles as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function test_company_hosts_use_separate_databases_and_cannot_cross_accounts(): void
    {
        $companyA = Organization::factory()->create();
        $companyB = Organization::factory()->create();
        $userA = User::factory()->admin()->for($companyA)->create();
        $userB = User::factory()->admin()->for($companyB)->create();
        $this->configureTenancy();
        $this->prepareDatabase($companyA, 'A product');
        $this->prepareDatabase($companyB, 'B product');

        $this->assertSame('A product', app(TenantContext::class)->run($companyA, fn () => Product::first()->name));
        $this->assertSame('B product', app(TenantContext::class)->run($companyB, fn () => Product::first()->name));

        $this->actingAs($userA)->getJson('http://'.$companyA->id.'.test/api/session')
            ->assertOk()->assertJsonPath('user.id', $userA->id);
        $this->getJson('http://'.$companyB->id.'.test/api/session')->assertForbidden();
        $this->getJson('http://admin.test/api/session')->assertForbidden();
        $this->assertNotSame($userA->id, $userB->id);
    }

    public function test_unknown_company_host_is_not_routed_to_the_central_database(): void
    {
        $this->configureTenancy();

        $this->getJson('http://999.test/api/session')->assertNotFound();
        $this->getJson('http://evil.example/api/session')->assertNotFound();

        $this->expectException(LogicException::class);
        Product::query()->count();
    }

    public function test_company_login_is_rejected_on_another_company_host(): void
    {
        $companyA = Organization::factory()->create();
        $companyB = Organization::factory()->create();
        $userB = User::factory()->admin()->for($companyB)->create();
        $this->configureTenancy();
        $this->prepareDatabase($companyA, 'A product');
        $this->prepareDatabase($companyB, 'B product');

        $this->postJson('http://'.$companyA->id.'.test/api/login', [
            'email' => $userB->email,
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_password_recovery_stays_on_the_account_company_host(): void
    {
        $companyA = Organization::factory()->create();
        $companyB = Organization::factory()->create();
        $userA = User::factory()->admin()->for($companyA)->create();
        $superadmin = User::factory()->superadmin()->for($companyB)->create();
        $this->configureTenancy();
        $this->prepareDatabase($companyA, 'A product');
        $this->prepareDatabase($companyB, 'B product');
        $this->withoutMiddleware(ThrottleRequests::class);
        Notification::fake();

        $this->postJson('http://'.$companyB->id.'.test/api/password/forgot', ['email' => $userA->email])->assertOk();
        $this->postJson('http://admin.test/api/password/forgot', ['email' => $userA->email])->assertOk();
        Notification::assertNothingSent();

        $this->postJson('http://'.$companyA->id.'.test/api/password/forgot', ['email' => $userA->email])->assertOk();
        Notification::assertSentTo($userA, ResetPassword::class);
        $notification = Notification::sent($userA, ResetPassword::class)->first();
        $url = (ResetPassword::$createUrlCallback)($userA, $notification->token);
        $this->assertSame($companyA->id.'.test', parse_url($url, PHP_URL_HOST));

        $this->postJson('http://'.$companyB->id.'.test/api/password/reset', [
            'email' => $userA->email,
            'token' => $notification->token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable();

        $this->postJson('http://'.$companyA->id.'.test/api/password/reset', [
            'email' => $userA->email,
            'token' => $notification->token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->postJson('http://admin.test/api/password/forgot', ['email' => $superadmin->email])->assertOk();
        Notification::assertSentTo($superadmin, ResetPassword::class);
        $adminNotification = Notification::sent($superadmin, ResetPassword::class)->first();
        $adminUrl = (ResetPassword::$createUrlCallback)($superadmin, $adminNotification->token);
        $this->assertSame('admin.test', parse_url($adminUrl, PHP_URL_HOST));
    }

    public function test_superadmin_can_read_company_branches_after_switching_database_context(): void
    {
        $company = Organization::factory()->create();
        $platform = Organization::factory()->create();
        $superadmin = User::factory()->superadmin()->for($platform)->create();
        $manager = User::factory()->for($company)->create();
        $this->configureTenancy();
        $this->prepareDatabase($company, 'A product');
        app(TenantContext::class)->run($company, fn () => DB::connection('tenant')->table('branch_user_access')->insert([
            'branch_id' => 1,
            'user_id' => $manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->actingAs($superadmin)->getJson('http://admin.test/api/clients/'.$company->id)
            ->assertOk()
            ->assertJsonPath('branches.0.name', 'Main Branch')
            ->assertJsonPath('products_count', 1);
        $users = $this->getJson('http://admin.test/api/users')->assertOk()->json();
        $managedUser = collect($users)->firstWhere('id', $manager->id);
        $this->assertSame('Main Branch', $managedUser['accessible_branches'][0]['name']);
    }

    public function test_superadmin_profile_can_be_updated_without_a_company_database(): void
    {
        $platform = Organization::factory()->create();
        $superadmin = User::factory()->superadmin()->for($platform)->create();
        $this->configureTenancy();

        $this->actingAs($superadmin)->putJson('http://admin.test/api/users/'.$superadmin->id, [
            'name' => 'Platform Owner',
            'email' => $superadmin->email,
            'role' => 'superadmin',
            'organization_id' => $platform->id,
            'active' => true,
        ])->assertOk()->assertJsonPath('name', 'Platform Owner');
    }

    private function configureTenancy(): void
    {
        config([
            'tenancy.enabled' => true,
            'tenancy.central_connection' => 'sqlite',
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'admin.test',
            'tenancy.sqlite_directory' => sys_get_temp_dir(),
        ]);
    }

    private function prepareDatabase(Organization $organization, string $productName): void
    {
        $database = 'uddog_test_'.Str::random(16);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'.sqlite';
        touch($path);
        $this->tenantFiles[] = $path;
        $organization->forceFill(['tenant_database' => $database])->save();

        app(TenantContext::class)->run($organization, function () use ($organization, $productName): void {
            Schema::connection('tenant')->create('products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->string('name');
            });
            Schema::connection('tenant')->create('branches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->string('name');
                $table->string('code');
                $table->boolean('is_default');
                $table->boolean('active');
                $table->timestamps();
            });
            Schema::connection('tenant')->create('documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
                $table->foreignId('branch_id');
            });
            Schema::connection('tenant')->create('contacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id');
            });
            Schema::connection('tenant')->create('product_stocks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id');
                $table->decimal('quantity_on_hand');
            });
            Schema::connection('tenant')->create('branch_user_access', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id');
                $table->foreignId('user_id');
                $table->timestamps();
            });
            DB::connection('tenant')->table('products')->insert([
                'organization_id' => $organization->id,
                'name' => $productName,
            ]);
            DB::connection('tenant')->table('branches')->insert([
                'organization_id' => $organization->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_default' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
