<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\SupplierAdvance;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierAdvanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_advance_and_apply_coded_purchase_deliveries_once(): void
    {
        $owner = User::factory()->admin()->create();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'ACI', 'type' => 'supplier']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Salt', 'unit' => 'pc', 'cost_price' => 5, 'sale_price' => 7, 'quantity_on_hand' => 0, 'reorder_level' => 0]);
        $branch = $owner->organization->branches()->firstOrFail();
        $this->actingAs($owner)->getJson('/api/supplier-advances')->assertForbidden();
        $setup = $owner->organization->setup();
        $setup['purchases']['allow_supplier_advances'] = true;
        $this->putJson('/api/system-setup', $setup)->assertOk();
        $this->postJson('/api/supplier-advances', [
            'contact_id' => $supplier->id, 'amount' => 100, 'expected_installments' => 2,
            'advance_date' => today()->toDateString(), 'payment_method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_installments');

        $advance = $this->postJson('/api/supplier-advances', [
            'contact_id' => $supplier->id, 'amount' => 100, 'expected_installments' => 3, 'advance_date' => today()->toDateString(),
            'payment_method' => 'cash', 'reference' => 'TT-001',
        ])->assertCreated()->json();

        $purchase = $this->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => today()->toDateString(),
            'payment_type' => 'due', 'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 10]],
        ])->assertCreated()->json();
        $this->assertSame('4.000', $product->fresh()->quantity_on_hand);

        $delivery = $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
            'document_id' => $purchase['id'], 'installment_code' => 'LOT-01',
        ])->assertCreated()->assertJsonPath('amount', '40.00')->json();
        $this->assertSame('4.000', $product->fresh()->quantity_on_hand);
        $this->assertSame('40.00', Document::findOrFail($purchase['id'])->amount_paid);
        $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
            'document_id' => $purchase['id'], 'installment_code' => 'LOT-02',
        ])->assertUnprocessable()->assertJsonValidationErrors('document_id');
        $tooLarge = $this->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => today()->toDateString(),
            'payment_type' => 'due', 'items' => [['product_id' => $product->id, 'quantity' => 7, 'unit_price' => 10]],
        ])->assertCreated()->json();
        $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
            'document_id' => $tooLarge['id'], 'installment_code' => 'LOT-02',
        ])->assertUnprocessable()->assertJsonValidationErrors('document_id');
        $this->postJson("/api/supplier-advances/{$advance['id']}/deliveries", [
            'document_id' => $tooLarge['id'], 'installment_code' => 'LOT-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('installment_code');

        $this->getJson("/api/reports?report=supplier_tt&branch_id={$branch->id}")
            ->assertOk()->assertJsonPath('summary.advance_sent', 100)
            ->assertJsonPath('summary.products_received', 40)
            ->assertJsonPath('summary.closing_balance', 60);
        $this->getJson("/api/reports?report=general_ledger&branch_id={$branch->id}&account=supplier_advances")
            ->assertOk()->assertJsonPath('summary.closing_balance', 60);
        $this->deleteJson("/api/supplier-advances/{$advance['id']}")->assertUnprocessable()->assertJsonValidationErrors('advance');
        $this->deleteJson("/api/documents/{$purchase['id']}")->assertUnprocessable();
        $stockBeforeReversal = $product->fresh()->quantity_on_hand;
        $this->deleteJson("/api/supplier-advances/{$advance['id']}/deliveries/{$delivery['id']}")->assertOk();
        $this->assertSame('0.00', Document::findOrFail($purchase['id'])->amount_paid);
        $this->assertSame($stockBeforeReversal, $product->fresh()->quantity_on_hand);
        $this->deleteJson("/api/supplier-advances/{$advance['id']}")->assertOk();
        $this->assertSame(0, SupplierAdvance::count());
    }

    public function test_staff_cannot_record_advance_or_backdate_a_purchase_or_sale(): void
    {
        $owner = User::factory()->admin()->create();
        $setup = $owner->organization->setup();
        $setup['purchases']['allow_supplier_advances'] = true;
        $this->actingAs($owner)->putJson('/api/system-setup', $setup)->assertOk();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'ACI', 'type' => 'supplier']);
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Buyer', 'type' => 'customer']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Salt', 'unit' => 'pc', 'cost_price' => 5, 'sale_price' => 7, 'quantity_on_hand' => 5, 'reorder_level' => 0]);
        $staff = User::factory()->staff()->for($owner->organization)->create(['permissions' => [Permission::Purchases->value, Permission::Sales->value]]);

        $this->actingAs($staff)->postJson('/api/supplier-advances', [
            'contact_id' => $supplier->id, 'amount' => 100, 'advance_date' => today()->toDateString(), 'payment_method' => 'cash',
        ])->assertForbidden();
        foreach ([['purchase', $supplier], ['sale', $customer]] as [$type, $contact]) {
            $this->postJson('/api/documents', [
                'type' => $type, 'contact_id' => $contact->id, 'document_date' => today()->subDay()->toDateString(),
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
            ])->assertUnprocessable()->assertJsonValidationErrors('document_date');
        }
    }
}
