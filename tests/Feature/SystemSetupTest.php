<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemSetupTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): array
    {
        return [
            'sales' => ['allow_due_sales' => false, 'allow_installment_sales' => false, 'allow_sales_returns' => false, 'allow_resales' => false, 'default_payment_method' => 'card'],
            'purchases' => ['allow_purchase_returns' => false],
            'store' => ['allow_stock_adjustments' => false, 'allow_stock_transfers' => false, 'default_reorder_level' => 4],
        ];
    }

    public function test_owner_can_save_company_settings_without_changing_existing_staff(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create(['permissions' => [Permission::Contacts->value]]);

        $this->actingAs($owner)->putJson('/api/system-setup', $this->settings())
            ->assertOk()
            ->assertJsonPath('sales.default_payment_method', 'card')
            ->assertJsonPath('store.default_reorder_level', 4);

        $this->assertSame([Permission::Contacts->value], $staff->fresh()->permissions);
        $this->assertFalse($owner->organization->fresh()->setup()['sales']['allow_due_sales']);
    }

    public function test_owner_can_edit_company_name_phone_and_address(): void
    {
        $owner = User::factory()->admin()->create();
        $company = ['name' => 'হাবিবুর রহমান ডিম আড়ৎ', 'phone' => '01700000000', 'address' => 'ঢাকা, বাংলাদেশ'];

        $this->actingAs($owner)->putJson('/api/system-setup', [...$this->settings(), 'company' => $company])
            ->assertOk()
            ->assertJsonPath('company.name', $company['name'])
            ->assertJsonPath('company.phone', $company['phone'])
            ->assertJsonPath('company.address', $company['address']);

        $this->getJson('/api/system-setup')
            ->assertOk()
            ->assertJsonPath('company.name', $company['name'])
            ->assertJsonPath('company.phone', $company['phone'])
            ->assertJsonPath('company.address', $company['address']);

        $this->assertDatabaseHas('organizations', [
            'id' => $owner->organization_id,
            ...$company,
        ]);
    }

    public function test_owner_can_enable_cash_handover_without_enabling_it_for_other_companies(): void
    {
        $owner = User::factory()->admin()->create();
        $other = Organization::factory()->create();
        $settings = $owner->organization->setup();
        $this->assertFalse($settings['store']['allow_cash_handover']);
        $settings['store']['allow_cash_handover'] = true;

        $this->actingAs($owner)->putJson('/api/system-setup', $settings)
            ->assertOk()->assertJsonPath('store.allow_cash_handover', true);
        $this->getJson('/api/system-setup')->assertOk()->assertJsonPath('store.allow_cash_handover', true);
        $this->assertFalse($other->fresh()->setup()['store']['allow_cash_handover']);
    }

    public function test_staff_transaction_dates_are_today_only_until_the_owner_enables_custom_dates(): void
    {
        $owner = User::factory()->admin()->create();
        $manager = User::factory()->for($owner->organization)->create();
        $staff = User::factory()->staff()->for($owner->organization)->create();
        $otherCompany = Organization::factory()->create();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Supplier', 'type' => 'supplier']);
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Product', 'unit' => 'pc', 'cost_price' => 5, 'sale_price' => 10, 'quantity_on_hand' => 10, 'reorder_level' => 0]);
        $pastDate = now('Asia/Dhaka')->subDays(2)->toDateString();
        $futureDate = now('Asia/Dhaka')->addDays(2)->toDateString();
        $purchase = ['type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => $pastDate, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]]];
        $sale = ['type' => 'sale', 'contact_id' => $customer->id, 'document_date' => $futureDate, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]]];

        $this->actingAs($owner)->getJson('/api/system-setup')->assertOk()->assertJsonPath('store.allow_staff_custom_dates', false);
        $this->actingAs($manager)->postJson('/api/documents', $purchase)->assertUnprocessable()->assertJsonValidationErrors('document_date');
        $this->actingAs($staff)->postJson('/api/documents', $sale)->assertUnprocessable()->assertJsonValidationErrors('document_date');
        $this->assertDatabaseCount('documents', 0);

        $settings = $owner->organization->setup();
        $settings['store']['allow_staff_custom_dates'] = true;
        $this->actingAs($owner)->putJson('/api/system-setup', $settings)
            ->assertOk()->assertJsonPath('store.allow_staff_custom_dates', true);
        $this->assertFalse($otherCompany->fresh()->setup()['store']['allow_staff_custom_dates']);
        $this->actingAs($manager)->getJson('/api/session')->assertOk()->assertJsonPath('operational_setup.store.allow_staff_custom_dates', true);

        $this->actingAs($manager)->postJson('/api/documents', $purchase)->assertCreated();
        $this->actingAs($staff)->postJson('/api/documents', $sale)->assertCreated();
        $this->assertDatabaseCount('documents', 2);

        $settings['store']['allow_staff_custom_dates'] = false;
        $this->actingAs($owner)->putJson('/api/system-setup', $settings)
            ->assertOk()->assertJsonPath('store.allow_staff_custom_dates', false);
        $this->actingAs($staff)->postJson('/api/documents', $sale)->assertUnprocessable()->assertJsonValidationErrors('document_date');
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_duplicate_company_name_and_invalid_contact_details_are_rejected(): void
    {
        $owner = User::factory()->admin()->create();
        $otherCompany = Organization::factory()->create(['name' => 'Existing Store']);

        $this->actingAs($owner)->putJson('/api/system-setup', [
            ...$this->settings(),
            'company' => ['name' => $otherCompany->name, 'phone' => str_repeat('1', 51), 'address' => str_repeat('A', 1001)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['company.name', 'company.phone', 'company.address']);

        $this->assertSame($owner->organization->name, $owner->organization->fresh()->name);
        $this->assertNull($owner->organization->fresh()->phone);
        $this->assertNull($owner->organization->fresh()->address);
    }

    public function test_owner_can_configure_product_units_and_create_products_with_them(): void
    {
        $owner = User::factory()->admin()->create();
        $store = [...$this->settings()['store'], 'units' => ['pc', 'g', 'oz', 'ভরি']];

        $this->actingAs($owner)->putJson('/api/system-setup', [...$this->settings(), 'store' => $store])
            ->assertOk()
            ->assertJsonPath('store.units.2', 'oz');

        $this->postJson('/api/products', [
            'name' => 'Gold ring', 'unit' => 'ভরি', 'cost_price' => 0, 'sale_price' => 0,
            'reorder_level' => 0, 'active' => true,
        ])->assertCreated()->assertJsonPath('unit', 'ভরি');
    }

    public function test_owner_can_change_piece_unit_conversions_but_invalid_factor_is_rejected(): void
    {
        $owner = User::factory()->admin()->create();
        $settings = $owner->organization->setup();
        $settings['store']['unit_conversions']['hali'] = 6;

        $this->actingAs($owner)->putJson('/api/system-setup', $settings)
            ->assertOk()
            ->assertJsonPath('store.unit_conversions.hali', 6)
            ->assertJsonPath('store.unit_conversions.dozen', 12);

        $settings['store']['unit_conversions']['hali'] = 0;
        $this->putJson('/api/system-setup', $settings)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('store.unit_conversions.hali');

        $this->assertSame(6, $owner->organization->fresh()->setup()['store']['unit_conversions']['hali']);
    }

    public function test_operational_settings_do_not_assign_staff_permissions(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create(['permissions' => []]);
        $branch = $owner->organization->branches()->firstOrFail();

        $this->actingAs($owner)->putJson('/api/system-setup', [
            ...$this->settings(),
            'role_permissions' => ['staff' => [Permission::Sales->value]],
            'apply_existing' => ['staff'],
        ])->assertOk();

        $this->assertSame([], $staff->fresh()->permissions);
        $this->postJson('/api/users', [
            'name' => 'New staff', 'email' => 'new-staff@example.test', 'password' => 'password123',
            'role' => 'staff', 'active' => true, 'branch_ids' => [$branch->id],
        ])->assertCreated()->assertJsonPath('permissions', null);
    }

    public function test_settings_are_owner_only_and_isolated_by_company(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create();
        $otherOrganization = Organization::factory()->create();

        $this->actingAs($staff)->getJson('/api/system-setup')->assertForbidden();
        $this->putJson('/api/system-setup', $this->settings())->assertForbidden();
        $this->actingAs($owner)->putJson('/api/system-setup', $this->settings())->assertOk();

        $this->assertNull($otherOrganization->fresh()->system_settings);
    }

    public function test_invalid_settings_do_not_change_saved_settings(): void
    {
        $owner = User::factory()->admin()->create();

        $this->actingAs($owner)->putJson('/api/system-setup', [
            ...$this->settings(),
            'sales' => [...$this->settings()['sales'], 'default_payment_method' => 'bitcoin'],
            'store' => ['allow_stock_adjustments' => false, 'allow_stock_transfers' => false, 'default_reorder_level' => -1],
        ])->assertUnprocessable()->assertJsonValidationErrors(['sales.default_payment_method', 'store.default_reorder_level']);

        $this->assertNull($owner->organization->fresh()->system_settings);
    }

    public function test_disabled_sales_and_store_workflows_are_rejected_on_the_server(): void
    {
        $owner = User::factory()->admin()->create();
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Product', 'unit' => 'pc', 'cost_price' => 5, 'sale_price' => 10, 'quantity_on_hand' => 1, 'reorder_level' => 0]);
        $this->actingAs($owner)->putJson('/api/system-setup', $this->settings())->assertOk();

        $this->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => now()->toDateString(),
            'payment_type' => 'due', 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_type');
        $this->postJson("/api/products/{$product->id}/adjust", ['quantity_change' => 1, 'notes' => 'Count'])->assertUnprocessable();
        $this->postJson('/api/stock-transfers', [])->assertUnprocessable();

        $this->assertSame('1.000', $product->fresh()->quantity_on_hand);
    }
}
