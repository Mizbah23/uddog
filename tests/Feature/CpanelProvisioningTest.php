<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CpanelProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.enabled' => true,
            'tenancy.central_connection' => 'sqlite',
            'tenancy.base_domain' => 'localhost',
            'tenancy.central_host' => 'localhost',
            'tenancy.database_prefix' => 'cpanel_company_',
            'tenancy.provisioning_mode' => 'cpanel',
        ]);
    }

    public function test_superadmin_can_save_a_company_pending_cpanel_database_setup(): void
    {
        $superadmin = User::factory()->superadmin()->create();

        $response = $this->actingAs($superadmin)->postJson('http://localhost/api/clients', [
            'name' => 'Demo',
            'subdomain' => 'demo',
            'subscription_status' => 'trial',
            'owner_name' => 'Demo Owner',
            'owner_email' => 'demo-owner@example.test',
            'owner_password' => 'Password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('subdomain', 'demo')
            ->assertJsonPath('tenant_database', null);

        $company = Organization::query()->where('subdomain', 'demo')->firstOrFail();
        $this->assertSame('cpanel_company_'.$company->id, $response->json('expected_tenant_database'));
        $this->assertDatabaseHas('users', [
            'organization_id' => $company->id,
            'email' => 'demo-owner@example.test',
        ]);
        $this->getJson('http://demo.localhost/api/session')->assertNotFound();
    }

    public function test_pending_company_can_be_recovered_only_by_superadmin_with_expected_database(): void
    {
        $company = Organization::factory()->create(['subdomain' => 'demo']);
        config(['tenancy.enabled' => false]);
        $manager = User::factory()->for($company)->create();
        config(['tenancy.enabled' => true]);
        $superadmin = User::factory()->superadmin()->create();
        $database = 'cpanel_company_'.$company->id;

        $this->actingAs($superadmin)->getJson('http://localhost/api/clients')
            ->assertOk()
            ->assertJsonFragment(['expected_tenant_database' => $database]);

        auth()->logout();
        $this->postJson("http://localhost/api/clients/{$company->id}/provision", [
            'database' => $database,
        ])->assertUnauthorized();

        $this->actingAs($manager)->postJson("http://localhost/api/clients/{$company->id}/provision", [
            'database' => $database,
        ])->assertForbidden();

        $this->actingAs($superadmin)->postJson("http://localhost/api/clients/{$company->id}/provision", [
            'database' => 'another_company_database',
        ])->assertUnprocessable()->assertJsonValidationErrors('database');

        $this->mock(TenantProvisioner::class)
            ->shouldReceive('provisionExisting')
            ->once()
            ->withArgs(fn (Organization $given): bool => $given->id === $company->id);

        $this->postJson("http://localhost/api/clients/{$company->id}/provision", [
            'database' => $database,
        ])->assertOk()->assertJsonPath('id', $company->id);
    }

    public function test_cpanel_provisioning_rejects_an_unavailable_database(): void
    {
        $company = Organization::factory()->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->postJson("http://localhost/api/clients/{$company->id}/provision", [
            'database' => 'cpanel_company_'.$company->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('database');

        $this->assertNull($company->fresh()->tenant_database);
    }
}
