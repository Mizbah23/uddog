<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupRecords(): array
    {
        $user = User::factory()->create();
        $supplier = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Main supplier', 'type' => 'supplier']);
        $customer = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Retail customer', 'type' => 'customer']);
        $product = Product::create([
            'organization_id' => $user->organization_id,
            'sku' => 'ITEM-001', 'name' => 'Test item', 'unit' => 'pcs',
            'cost_price' => '10.00', 'sale_price' => '15.00', 'reorder_level' => '2',
        ]);

        return [$user, $supplier, $customer, $product];
    }

    private function payload(string $type, Contact $contact, Product $product, string $quantity, string $price): array
    {
        return [
            'type' => $type, 'contact_id' => $contact->id, 'document_date' => '2026-09-21',
            'discount' => '0', 'tax' => '0',
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
        ];
    }

    public function test_purchase_sale_resale_and_return_update_stock_and_ledger(): void
    {
        [$user, $supplier, $customer, $product] = $this->setupRecords();
        $this->actingAs($user);

        $purchase = $this->postJson('/api/documents', $this->payload('purchase', $supplier, $product, '10', '10.00'))
            ->assertCreated()->assertJsonPath('number', 'PUR-000001')->json();
        $this->assertSame('10.000', $product->fresh()->quantity_on_hand);

        $this->postJson('/api/documents', $this->payload('sale', $customer, $product, '3', '15.00'))
            ->assertCreated()->assertJsonPath('total', '45.00');
        $this->postJson('/api/documents', $this->payload('resale', $customer, $product, '2', '15.00'))
            ->assertCreated();
        $return = $this->payload('purchase_return', $supplier, $product, '1', '10.00');
        $return['purchase_id'] = $purchase['id'];
        $this->postJson('/api/documents', $return)->assertCreated();

        $this->assertSame('4.000', $product->fresh()->quantity_on_hand);
        $this->assertSame(4, StockMovement::query()->count());
        $this->assertSame('-1.000', StockMovement::query()->latest('id')->first()->quantity_change);
    }

    public function test_rejected_sale_rolls_back_document_and_stock(): void
    {
        [$user, , $customer, $product] = $this->setupRecords();
        $this->actingAs($user);
        $this->postJson('/api/documents', $this->payload('sale', $customer, $product, '1', '15.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame('0.000', $product->fresh()->quantity_on_hand);
        $this->assertSame(0, Document::query()->count());
    }

    public function test_sale_profit_uses_the_cost_captured_when_the_sale_is_created(): void
    {
        [$user, $supplier, $customer, $product] = $this->setupRecords();
        $product->update(['cost_price' => '0.00']);
        $this->actingAs($user);
        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $product, '5', '10.00'))->assertCreated();
        $this->assertSame('10.00', $product->fresh()->cost_price);
        $salePayload = $this->payload('sale', $customer, $product, '2', '15.00');
        $salePayload['discount'] = '2.00';

        $sale = $this->postJson('/api/documents', $salePayload)
            ->assertCreated()
            ->assertJsonPath('stock_profit', '10.00')
            ->assertJsonPath('total_profit', '8.00')
            ->assertJsonPath('items.0.cost_price', '10.00')
            ->json();

        $product->update(['cost_price' => '14.00']);

        $this->getJson('/api/documents?type=sale')
            ->assertOk()
            ->assertJsonPath('0.id', $sale['id'])
            ->assertJsonPath('0.stock_profit', '10.00')
            ->assertJsonPath('0.total_profit', '8.00');
    }

    public function test_dashboard_customer_due_list_groups_customers_and_sorts_highest_balance_first(): void
    {
        [$user, $supplier, $customer, $product] = $this->setupRecords();
        $secondCustomer = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Second customer', 'type' => 'customer']);
        $this->actingAs($user);
        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $product, '20', '10.00'))->assertCreated();

        $firstSale = $this->postJson('/api/documents', $this->payload('sale', $customer, $product, '3', '15.00'))->assertCreated()->json();
        Document::query()->findOrFail($firstSale['id'])->update(['amount_paid' => 0]);
        $secondSale = $this->postJson('/api/documents', $this->payload('resale', $customer, $product, '2', '15.00'))->assertCreated()->json();
        Document::query()->findOrFail($secondSale['id'])->update(['amount_paid' => 0]);
        $otherSale = $this->postJson('/api/documents', $this->payload('sale', $secondCustomer, $product, '1', '15.00'))->assertCreated()->json();
        Document::query()->findOrFail($otherSale['id'])->update(['amount_paid' => 0]);

        $this->getJson('/api/overview')
            ->assertOk()
            ->assertJsonPath('customer_due_list.0.customer', 'Retail customer')
            ->assertJsonPath('customer_due_list.0.invoices_count', 2)
            ->assertJsonPath('customer_due_list.0.amount_due', '75.00')
            ->assertJsonPath('customer_due_list.1.customer', 'Second customer')
            ->assertJsonPath('customer_due_list.1.amount_due', '15.00');
    }

    public function test_dashboard_chart_uses_calendar_months_and_nets_returns_in_their_document_month(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31));
        [$user, $supplier, $customer, $product] = $this->setupRecords();
        $this->actingAs($user);

        $purchasePayload = $this->payload('purchase', $supplier, $product, '10', '10.00');
        $purchasePayload['document_date'] = '2026-10-31';
        $purchase = $this->postJson('/api/documents', $purchasePayload)->assertCreated()->json();
        $purchaseReturn = $this->payload('purchase_return', $supplier, $product, '2', '10.00');
        $purchaseReturn['purchase_id'] = $purchase['id'];
        $purchaseReturn['document_date'] = '2026-10-31';
        $this->postJson('/api/documents', $purchaseReturn)->assertCreated();

        $salePayload = $this->payload('sale', $customer, $product, '2', '15.00');
        $salePayload['document_date'] = '2026-10-31';
        $sale = $this->postJson('/api/documents', $salePayload)->assertCreated()->json();
        $saleReturn = $this->payload('sale_return', $customer, $product, '1', '15.00');
        $saleReturn['sale_id'] = $sale['id'];
        $saleReturn['document_date'] = '2026-10-31';
        $this->postJson('/api/documents', $saleReturn)->assertCreated();

        $this->getJson('/api/overview')
            ->assertOk()
            ->assertJsonPath('monthly_totals.0.month', 'Jan')
            ->assertJsonPath('monthly_totals.1.month', 'Feb')
            ->assertJsonPath('monthly_totals.9.month', 'Oct')
            ->assertJsonPath('monthly_totals.9.sales', 15)
            ->assertJsonPath('monthly_totals.9.purchases', 80);
    }

    public function test_product_can_be_created_without_default_cost_or_sale_prices(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/products', [
            'name' => 'Price at transaction product',
            'unit' => 'pc',
            'reorder_level' => 0,
            'active' => true,
        ])->assertCreated()
            ->assertJsonPath('cost_price', '0.00')
            ->assertJsonPath('sale_price', '0.00');
    }

    public function test_return_cannot_exceed_original_purchase(): void
    {
        [$user, $supplier, , $product] = $this->setupRecords();
        $this->actingAs($user);
        $purchase = $this->postJson('/api/documents', $this->payload('purchase', $supplier, $product, '3', '10.00'))->assertCreated()->json();
        $return = $this->payload('purchase_return', $supplier, $product, '4', '10.00');
        $return['purchase_id'] = $purchase['id'];
        $this->postJson('/api/documents', $return)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame('3.000', $product->fresh()->quantity_on_hand);
        $this->assertSame(1, Document::query()->count());
    }

    public function test_sales_return_restores_stock_and_cannot_exceed_the_remaining_sale_quantity(): void
    {
        [$user, $supplier, $customer, $product] = $this->setupRecords();
        $this->actingAs($user);
        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $product, '5', '10.00'))->assertCreated();
        $sale = $this->postJson('/api/documents', $this->payload('sale', $customer, $product, '3', '15.00'))
            ->assertCreated()
            ->json();
        $this->assertSame('2.000', $product->fresh()->quantity_on_hand);

        $return = $this->payload('sale_return', $customer, $product, '2', '1.00');
        $return['sale_id'] = $sale['id'];
        $this->postJson('/api/documents', $return)
            ->assertCreated()
            ->assertJsonPath('number', 'SRT-000003')
            ->assertJsonPath('sale_id', $sale['id'])
            ->assertJsonPath('total', '30.00')
            ->assertJsonPath('items.0.unit_price', '15.00')
            ->assertJsonPath('items.0.cost_price', '10.00');
        $this->assertSame('4.000', $product->fresh()->quantity_on_hand);

        $secondReturn = $this->payload('sale_return', $customer, $product, '2', '15.00');
        $secondReturn['sale_id'] = $sale['id'];
        $this->postJson('/api/documents', $secondReturn)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
        $this->assertSame('4.000', $product->fresh()->quantity_on_hand);

        $this->getJson('/api/overview')
            ->assertOk()
            ->assertJsonPath('sales_total', 15);
    }

    public function test_pos_sale_records_payment_profit_and_stock_movement(): void
    {
        [$user, $supplier, $customer, $product] = $this->setupRecords();
        $this->actingAs($user);
        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $product, '5', '10.00'))->assertCreated();

        $payload = $this->payload('sale', $customer, $product, '2', '18.00');
        $payload += [
            'sale_channel' => 'pos',
            'payment_method' => 'cash',
            'amount_paid' => '34.00',
        ];
        $payload['discount'] = '2.00';

        $this->postJson('/api/documents', $payload)
            ->assertCreated()
            ->assertJsonPath('sale_channel', 'pos')
            ->assertJsonPath('payment_method', 'cash')
            ->assertJsonPath('amount_paid', '34.00')
            ->assertJsonPath('balance_due', '0.00')
            ->assertJsonPath('stock_profit', '16.00')
            ->assertJsonPath('total_profit', '14.00');

        $this->assertSame('3.000', $product->fresh()->quantity_on_hand);
        $this->assertSame('-2.000', StockMovement::query()->latest('id')->value('quantity_change'));

        $payload['amount_paid'] = '35.00';
        $this->postJson('/api/documents', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount_paid');
        $this->assertSame('3.000', $product->fresh()->quantity_on_hand);
    }

    public function test_adjustment_requires_reason_and_cannot_make_stock_negative(): void
    {
        [$user, , , $product] = $this->setupRecords();
        $this->actingAs($user);
        $this->postJson("/api/products/{$product->id}/adjust", ['quantity_change' => '5', 'notes' => 'Opening count'])->assertCreated();
        $this->postJson("/api/products/{$product->id}/adjust", ['quantity_change' => '-6', 'notes' => 'Count correction'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity_change');
        $this->assertSame('5.000', $product->fresh()->quantity_on_hand);
    }
}
