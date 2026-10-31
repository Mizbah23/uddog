<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantOperationsTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tenantFile = null;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        config(['tenancy.enabled' => false]);
        if ($this->tenantFile && is_file($this->tenantFile)) {
            unlink($this->tenantFile);
        }

        parent::tearDown();
    }

    public function test_company_product_and_contact_writes_stay_out_of_the_central_database(): void
    {
        $company = Organization::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();
        $database = 'uddog_test_'.Str::random(16);
        $this->tenantFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'.sqlite';
        touch($this->tenantFile);
        $company->forceFill(['tenant_database' => $database])->save();
        config([
            'tenancy.enabled' => true,
            'tenancy.central_connection' => 'sqlite',
            'tenancy.base_domain' => 'test',
            'tenancy.central_host' => 'admin.test',
            'tenancy.sqlite_directory' => sys_get_temp_dir(),
        ]);

        app(TenantContext::class)->run($company, function () use ($company, $admin): void {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]));
            DB::connection('tenant')->table('organizations')->insert((array) DB::connection('sqlite')->table('organizations')->find($company->id));
            DB::connection('tenant')->table('users')->insert([
                'id' => $admin->id,
                'organization_id' => $company->id,
                'name' => 'Shadow user',
                'email' => 'shadow@tenant.invalid',
                'password' => bcrypt(Str::random(40)),
                'role' => 'admin',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('tenant')->table('branches')->insert([
                'organization_id' => $company->id,
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_default' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $customer = $this->actingAs($admin)->postJson('http://'.$company->id.'.test/api/contacts', [
            'name' => 'Tenant Customer',
            'type' => 'customer',
        ])->assertCreated()->json();
        $supplier = $this->postJson('http://'.$company->id.'.test/api/contacts', [
            'name' => 'Tenant Supplier',
            'type' => 'supplier',
        ])->assertCreated()->json();
        $product = $this->postJson('http://'.$company->id.'.test/api/products', [
            'name' => 'Tenant Product',
            'unit' => 'pc',
            'cost_price' => 10,
            'sale_price' => 20,
        ])->assertCreated()->json();
        $this->postJson('http://'.$company->id.'.test/api/documents', [
            'type' => 'purchase',
            'contact_id' => $supplier['id'],
            'document_date' => '2026-10-10',
            'items' => [['product_id' => $product['id'], 'quantity' => 5, 'unit_price' => 10]],
        ])->assertCreated();
        $sale = $this->postJson('http://'.$company->id.'.test/api/documents', [
            'type' => 'sale',
            'contact_id' => $customer['id'],
            'document_date' => '2026-10-10',
            'payment_type' => 'due',
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'unit_price' => 20]],
        ])->assertCreated()->json();
        $this->postJson('http://'.$company->id.'.test/api/documents/'.$sale['id'].'/corrections', [
            'correction_version' => $sale['correction_version'],
            'reason' => 'Correct tenant sale quantity', 'discount' => 0, 'tax' => 0,
            'items' => [['id' => $sale['items'][0]['id'], 'quantity' => 2, 'unit_price' => 20]],
        ])->assertOk()->assertJsonPath('total', '40.00');
        $this->assertSame(0, DB::connection('sqlite')->table('sale_corrections')->count());

        $this->assertSame(0, DB::connection('sqlite')->table('contacts')->where('organization_id', $company->id)->count());
        $this->assertSame(0, DB::connection('sqlite')->table('products')->where('organization_id', $company->id)->count());
        $this->assertSame(0, DB::connection('sqlite')->table('documents')->where('organization_id', $company->id)->count());
        app(TenantContext::class)->run($company, function () use ($company): void {
            $this->assertSame(2, DB::connection('tenant')->table('contacts')->where('organization_id', $company->id)->count());
            $this->assertSame(1, DB::connection('tenant')->table('products')->where('organization_id', $company->id)->count());
            $this->assertSame(2, DB::connection('tenant')->table('documents')->where('organization_id', $company->id)->count());
            $this->assertSame(1, DB::connection('tenant')->table('sale_corrections')->where('organization_id', $company->id)->count());
            $this->assertSame(3, (int) DB::connection('tenant')->table('product_stocks')->value('quantity_on_hand'));
        });
        $company->update(['ecommerce_enabled' => true]);
        $this->putJson('http://'.$company->id.'.test/api/products/'.$product['id'].'/ecommerce', ['ecommerce_visible' => true])->assertOk();
        $this->getJson('http://'.$company->id.'.test/ecomm/catalog')->assertOk()->assertJsonPath('products.0.id', $product['id'])->assertJsonPath('products.0.in_stock', true);
        $this->postJson('http://'.$company->id.'.test/ecomm/orders', [
            'checkout_token' => (string) Str::uuid(), 'customer_name' => 'Tenant online customer', 'phone' => '01712345678',
            'address' => 'Tenant delivery address', 'expected_total' => 20, 'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame(0, DB::connection('sqlite')->table('ecommerce_orders')->count());
        $this->assertSame(0, DB::connection('sqlite')->table('package_usages')->count());
        app(TenantContext::class)->run($company, function () use ($company): void {
            $this->assertSame(1, DB::connection('tenant')->table('ecommerce_orders')->where('organization_id', $company->id)->count());
            $this->assertSame(1, (int) DB::connection('tenant')->table('package_usages')->where('organization_id', $company->id)->value('sale_invoices'));
        });
        $company->update(['package_limits' => ['sale_invoices' => 1]]);
        $this->postJson('http://'.$company->id.'.test/api/documents', [
            'type' => 'sale', 'contact_id' => $customer['id'], 'document_date' => now('Asia/Dhaka')->toDateString(),
            'payment_type' => 'due', 'items' => [['product_id' => $product['id'], 'quantity' => 1, 'unit_price' => 20]],
        ])->assertUnprocessable()->assertJsonValidationErrors('package_limits');

    }

    public function test_enabling_ecommerce_repairs_a_missing_tenant_organization_before_syncing_the_owner(): void
    {
        $company = Organization::factory()->create();
        $owner = User::factory()->admin()->for($company)->create();
        $superadmin = User::factory()->superadmin()->create();
        $database = 'uddog_test_'.Str::random(16);
        $this->tenantFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database.'.sqlite';
        touch($this->tenantFile);
        $company->forceFill(['tenant_database' => $database])->save();
        config([
            'tenancy.enabled' => true, 'tenancy.central_connection' => 'sqlite',
            'tenancy.base_domain' => 'test', 'tenancy.central_host' => 'admin.test',
            'tenancy.sqlite_directory' => sys_get_temp_dir(),
        ]);
        app(TenantContext::class)->run($company, function (): void {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--force' => true]));
            $this->assertSame(0, DB::connection('tenant')->table('organizations')->count());
        });
        $payload = [
            'name' => $company->name, 'plan_name' => 'Premium', 'annual_renew_fee' => '12000.00', 'subscription_status' => 'active', 'ecommerce_enabled' => true,
            'owner_name' => $owner->name, 'owner_email' => $owner->email, 'owner_active' => true,
        ];
        $this->actingAs($superadmin)->putJson('http://admin.test/api/clients/'.$company->id, $payload)
            ->assertOk()->assertJsonPath('ecommerce_enabled', true);
        $this->putJson('http://admin.test/api/clients/'.$company->id, [...$payload, 'ecommerce_enabled' => false])
            ->assertOk()->assertJsonPath('ecommerce_enabled', false);
        app(TenantContext::class)->run($company, function () use ($company, $owner): void {
            $this->assertSame(1, DB::connection('tenant')->table('organizations')->where('id', $company->id)->count());
            $this->assertSame(0, (int) DB::connection('tenant')->table('organizations')->where('id', $company->id)->value('ecommerce_enabled'));
            $this->assertSame(12000.0, (float) DB::connection('tenant')->table('organizations')->where('id', $company->id)->value('annual_renew_fee'));
            $this->assertSame(1, DB::connection('tenant')->table('users')->where('id', $owner->id)->where('organization_id', $company->id)->count());
        });
    }
}
