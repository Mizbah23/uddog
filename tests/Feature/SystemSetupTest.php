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
