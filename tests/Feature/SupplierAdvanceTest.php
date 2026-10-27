<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceDelivery;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierAdvanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_records_a_standalone_advance_and_coded_deliveries_without_changing_existing_transactions(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $this->actingAs($owner)->getJson('/api/supplier-advances')->assertForbidden();
        $setup = $owner->organization->setup();
        $setup['supplier_advances']['enabled'] = true;
        $this->putJson('/api/system-setup', $setup)->assertOk();

        $today = now('Asia/Dhaka')->toDateString();
        $this->postJson('/api/supplier-advances', [
            'company_name' => 'ACI Salt', 'amount' => 100, 'expected_installments' => 2,
            'advance_date' => $today, 'payment_method' => 'bank_transfer',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_installments');

        $advance = $this->postJson('/api/supplier-advances', [
            'company_name' => 'ACI Salt', 'amount' => 100, 'expected_installments' => 3,
            'advance_date' => $today, 'payment_method' => 'bank_transfer', 'reference' => 'TT-001',
        ])->assertCreated()->assertJsonPath('company_name', 'ACI Salt')->json();

        $first = $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
            'installment_code' => 'LOT-01', 'product_details' => 'Salt cartons',
            'amount' => 40, 'delivery_date' => $today,
        ])->assertCreated()->assertJsonPath('amount', '40.00')->json();
        $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
            'installment_code' => 'LOT-01', 'product_details' => 'More salt',
            'amount' => 30, 'delivery_date' => $today,
        ])->assertUnprocessable()->assertJsonValidationErrors('installment_code');
        $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
            'installment_code' => 'LOT-02', 'product_details' => 'More salt',
            'amount' => 70, 'delivery_date' => $today,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        foreach ([['LOT-02', 30], ['LOT-03', 30]] as [$code, $amount]) {
            $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
                'installment_code' => $code, 'product_details' => 'Salt cartons',
                'amount' => $amount, 'delivery_date' => $today,
            ])->assertCreated();
        }
        $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
            'installment_code' => 'LOT-04', 'product_details' => 'Salt cartons',
            'amount' => 1, 'delivery_date' => $today,
        ])->assertUnprocessable()->assertJsonValidationErrors('installment_code');

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('purchase_payments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->getJson("/api/reports?report=supplier_tt&branch_id={$branch->id}")
            ->assertOk()->assertJsonPath('summary.advance_sent', 100)
            ->assertJsonPath('summary.products_received', 100)
            ->assertJsonPath('summary.closing_balance', 0)
            ->assertJsonCount(4, 'rows');
        $this->getJson("/api/reports?report=supplier_tt&branch_id={$branch->id}&search=other")
            ->assertOk()->assertJsonCount(0, 'rows');
        $this->deleteJson("/api/supplier-advances/{$advance['id']}")->assertUnprocessable();
        $this->deleteJson("/api/supplier-advances/{$advance['id']}/deliveries/{$first['id']}")->assertOk();
        $this->assertSame(60.0, (float) SupplierAdvanceDelivery::sum('amount'));
        foreach (SupplierAdvanceDelivery::query()->pluck('id') as $deliveryId) {
            $this->deleteJson("/api/supplier-advances/{$advance['id']}/deliveries/{$deliveryId}")->assertOk();
        }
        $this->deleteJson("/api/supplier-advances/{$advance['id']}")->assertOk();
        $this->assertSame(0, SupplierAdvance::count());
    }

    public function test_staff_cannot_access_advance_records_or_backdate_a_purchase_or_sale(): void
    {
        $owner = User::factory()->admin()->create();
        $setup = $owner->organization->setup();
        $setup['supplier_advances']['enabled'] = true;
        $this->actingAs($owner)->putJson('/api/system-setup', $setup)->assertOk();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'ACI', 'type' => 'supplier']);
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Buyer', 'type' => 'customer']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Salt', 'unit' => 'pc', 'cost_price' => 5, 'sale_price' => 7, 'quantity_on_hand' => 5, 'reorder_level' => 0]);
        $staff = User::factory()->staff()->for($owner->organization)->create(['permissions' => [Permission::Purchases->value, Permission::Sales->value]]);

        $this->actingAs($staff)->getJson('/api/supplier-advances')->assertForbidden();
        $this->getJson('/api/reports?report=supplier_tt')->assertForbidden();
        $this->postJson('/api/supplier-advances', [
            'company_name' => 'ACI', 'amount' => 100, 'expected_installments' => 3,
            'advance_date' => now('Asia/Dhaka')->toDateString(), 'payment_method' => 'cash',
        ])->assertForbidden();
        foreach ([['purchase', $supplier], ['sale', $customer]] as [$type, $contact]) {
            $this->postJson('/api/documents', [
                'type' => $type, 'contact_id' => $contact->id,
                'document_date' => now('Asia/Dhaka')->subDay()->toDateString(),
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
            ])->assertUnprocessable()->assertJsonValidationErrors('document_date');
        }
    }
}
