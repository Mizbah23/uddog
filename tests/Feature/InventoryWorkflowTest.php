<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\PurchasePayment;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function setupRecords(bool $asOwner = false): array
    {
        $user = $asOwner ? User::factory()->admin()->create() : User::factory()->create();
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

    public function test_three_sales_products_share_one_egg_stock_and_returns_restore_the_original_source(): void
    {
        [$user, $supplier, $customer, $eggs] = $this->setupRecords(true);
        $user->organization->update(['shared_stock_sales' => true]);
        $eggs->update(['name' => 'লেয়ার মুরগির ডিম', 'unit' => 'pc']);
        $variants = collect(['লেয়ার মুরগি সিদ্ধ', 'লেয়ার মুরগি ডিম', 'লেয়ার খুচরা'])
            ->map(fn (string $name) => Product::create([
                'organization_id' => $user->organization_id, 'name' => $name, 'unit' => 'pc',
                'cost_price' => '0.00', 'sale_price' => '15.00', 'reorder_level' => '0',
                'stock_source_product_id' => $eggs->id, 'stock_source_quantity' => '1.000',
            ]));
        $this->actingAs($user);

        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $eggs, '5000', '10.00'))->assertCreated();
        $sale = $this->payload('sale', $customer, $variants[0], '1000', '14.00');
        $sale['items'][] = ['product_id' => $variants[1]->id, 'quantity' => '2000', 'unit_price' => '15.00'];
        $sale['items'][] = ['product_id' => $variants[2]->id, 'quantity' => '500', 'unit_price' => '16.00'];
        $result = $this->postJson('/api/documents', $sale)->assertCreated()
            ->assertJsonPath('items.0.stock_product_id', $eggs->id)
            ->assertJsonPath('items.0.stock_quantity', '1000.000')
            ->assertJsonPath('items.0.cost_price', '10.00')->json();

        $this->assertSame('1500.000', $eggs->fresh()->quantity_on_hand);
        $this->assertSame('0.000', $variants[0]->fresh()->quantity_on_hand);
        $this->assertSame('17000.00', $result['stock_profit']);
        $this->assertSame([$eggs->id, $eggs->id, $eggs->id], StockMovement::query()->where('type', 'sale')->pluck('product_id')->all());

        $oversell = $this->payload('sale', $customer, $variants[0], '1000', '14.00');
        $oversell['items'][] = ['product_id' => $variants[1]->id, 'quantity' => '600', 'unit_price' => '15.00'];
        $this->postJson('/api/documents', $oversell)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame('1500.000', $eggs->fresh()->quantity_on_hand);

        $user->organization->update(['shared_stock_sales' => false]);
        $this->postJson('/api/documents', $this->payload('sale', $customer, $variants[0], '1', '14.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $return = $this->payload('sale_return', $customer, $variants[0], '250', '14.00');
        $return['sale_id'] = $result['id'];
        $this->postJson('/api/documents', $return)->assertCreated()->assertJsonPath('items.0.stock_quantity', '250.000');
        $this->assertSame('1750.000', $eggs->fresh()->quantity_on_hand);

        $branchId = $user->organization->branches()->firstOrFail()->id;
        $this->getJson('/api/reports?report=product_lifecycle_ledger&product_id='.$eggs->id.'&branch_id='.$branchId)
            ->assertOk()
            ->assertJsonPath('summary.closing_stock', 1750)
            ->assertJsonPath('summary.sales', 48500)
            ->assertJsonPath('summary.cogs', 32500)
            ->assertJsonPath('summary.realized_profit', 16000);

        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $variants[0], '1', '10.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_product_api_exposes_shared_availability_and_rejects_invalid_stock_links(): void
    {
        [$user, $supplier, , $eggs] = $this->setupRecords(true);
        $eggs->update(['unit' => 'pc']);
        $this->actingAs($user);
        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $eggs, '12', '10.00'))->assertCreated();

        $payload = [
            'name' => 'লেয়ার মুরগি সিদ্ধ', 'unit' => 'pc', 'cost_price' => '0.00',
            'sale_price' => '15.00', 'reorder_level' => '0', 'active' => true,
            'stock_source_product_id' => $eggs->id, 'stock_source_quantity' => '1.000',
        ];
        $this->postJson('/api/products', $payload)->assertUnprocessable()->assertJsonValidationErrors('stock_source_product_id');
        $user->organization->update(['shared_stock_sales' => true]);
        $variant = $this->postJson('/api/products', $payload)->assertCreated()->assertJsonPath('stock_source_product_id', $eggs->id)->json();

        $this->getJson('/api/products')->assertOk()
            ->assertJsonFragment(['id' => $variant['id'], 'available_quantity' => '12.000', 'source_quantity_on_hand' => '12.000', 'quantity_on_hand' => '0.000']);
        $this->putJson('/api/products/'.$variant['id'], [...$payload, 'stock_source_quantity' => '2.000'])
            ->assertOk();
        $this->getJson('/api/products')->assertOk()
            ->assertJsonFragment(['id' => $variant['id'], 'available_quantity' => '6.000', 'source_quantity_on_hand' => '12.000', 'quantity_on_hand' => '0.000']);
        $this->putJson('/api/products/'.$eggs->id, [
            'name' => $eggs->name, 'unit' => 'pc', 'cost_price' => '10.00',
            'sale_price' => '15.00', 'reorder_level' => '0',
            'stock_source_product_id' => $variant['id'], 'stock_source_quantity' => '1.000',
        ])->assertUnprocessable()->assertJsonValidationErrors('stock_source_product_id');
        $this->deleteJson('/api/products/'.$eggs->id)->assertUnprocessable()->assertJsonValidationErrors('product');
    }

    public function test_partial_returns_of_a_converted_sales_product_reconcile_to_the_original_stock_quantity(): void
    {
        [$user, $supplier, $customer, $source] = $this->setupRecords();
        $user->organization->update(['shared_stock_sales' => true]);
        $source->update(['unit' => 'g']);
        $variant = Product::create([
            'organization_id' => $user->organization_id, 'name' => 'Half-source item', 'unit' => 'g',
            'cost_price' => '0.00', 'sale_price' => '10.00', 'reorder_level' => '0',
            'stock_source_product_id' => $source->id, 'stock_source_quantity' => '0.500',
        ]);
        $this->actingAs($user);
        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $source, '1', '10.00'))->assertCreated();
        $sale = $this->postJson('/api/documents', $this->payload('sale', $customer, $variant, '0.002', '10.00'))
            ->assertCreated()->assertJsonPath('items.0.stock_quantity', '0.001')->json();

        $return = $this->payload('sale_return', $customer, $variant, '0.001', '10.00');
        $return['sale_id'] = $sale['id'];
        $this->postJson('/api/documents', $return)->assertCreated()->assertJsonPath('items.0.stock_quantity', '0.001');
        $this->postJson('/api/documents', $return)->assertCreated()->assertJsonPath('items.0.stock_quantity', '0.000');

        $this->assertSame('1.000', $source->fresh()->quantity_on_hand);
        $this->assertSame('0.001', $sale['items'][0]['stock_quantity']);
    }

    public function test_piece_products_reject_fractional_purchases_sales_and_adjustments(): void
    {
        [$user, $supplier, $customer, $eggs] = $this->setupRecords(asOwner: true);
        $eggs->update(['unit' => 'pc']);
        $user->organization->update(['shared_stock_sales' => true]);
        $variant = Product::create([
            'organization_id' => $user->organization_id, 'name' => 'Boiled egg', 'unit' => 'pc',
            'cost_price' => '0.00', 'sale_price' => '15.00', 'reorder_level' => '0',
            'stock_source_product_id' => $eggs->id, 'stock_source_quantity' => '11.250',
        ]);
        $this->actingAs($user);

        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $eggs, '0.5', '10.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/documents', $this->payload('purchase', $supplier, $eggs, '20', '10.00'))->assertCreated();
        $this->postJson('/api/documents', $this->payload('sale', $customer, $eggs, '0.5', '15.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/documents', $this->payload('sale', $customer, $variant, '1', '15.00'))
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/products/'.$eggs->id.'/adjust', ['quantity_change' => '-0.5', 'notes' => 'Count correction'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity_change');
        $this->assertSame('20.000', $eggs->fresh()->quantity_on_hand);
    }

    public function test_owner_can_delete_clean_sale_and_purchase_and_restore_stock_and_cost(): void
    {
        [$owner, $supplier, $customer, $product] = $this->setupRecords(asOwner: true);
        $this->actingAs($owner);
        $purchase = $this->postJson('/api/documents', [
            ...$this->payload('purchase', $supplier, $product, '10', '20.00'),
            'payment_type' => 'due',
        ])->assertCreated()->json();
        $sale = $this->postJson('/api/documents', [
            ...$this->payload('sale', $customer, $product, '2', '30.00'),
            'payment_type' => 'due',
        ])->assertCreated()->json();
        $this->assertSame('8.000', $product->fresh()->quantity_on_hand);

        $this->deleteJson('/api/documents/'.$sale['id'])->assertOk();
        $this->assertSame('10.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseMissing('documents', ['id' => $sale['id']]);
        $this->assertDatabaseMissing('sale_payments', ['document_id' => $sale['id']]);

        $this->deleteJson('/api/documents/'.$purchase['id'])->assertOk();
        $this->assertSame('0.000', $product->fresh()->quantity_on_hand);
        $this->assertSame('10.00', $product->fresh()->cost_price);
        $this->assertDatabaseMissing('documents', ['id' => $purchase['id']]);
        $this->assertDatabaseMissing('purchase_payments', ['document_id' => $purchase['id']]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_paid_invoices_require_explicit_payment_deletion_before_owner_can_delete_them(): void
    {
        [$owner, $supplier, $customer, $product] = $this->setupRecords(asOwner: true);
        $this->actingAs($owner);
        $purchase = $this->postJson('/api/documents', [
            ...$this->payload('purchase', $supplier, $product, '10', '20.00'),
            'payment_type' => 'full', 'payment_method' => 'cash',
        ])->assertCreated()->json();
        $sale = $this->postJson('/api/documents', [
            ...$this->payload('sale', $customer, $product, '2', '30.00'),
            'payment_type' => 'full', 'payment_method' => 'cash',
        ])->assertCreated()->json();

        $this->deleteJson('/api/documents/'.$sale['id'])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->deleteJson('/api/documents/'.$purchase['id'])->assertUnprocessable()->assertJsonValidationErrors('document');
        $salePaymentId = $sale['payments'][0]['id'];
        $purchasePaymentId = PurchasePayment::query()->where('document_id', $purchase['id'])->firstOrFail()->id;
        $staff = User::factory()->create(['organization_id' => $owner->organization_id]);
        $this->actingAs($staff)->deleteJson('/api/documents/'.$sale['id'].'/payments/'.$salePaymentId)->assertForbidden();
        $this->actingAs($owner)->deleteJson('/api/documents/'.$sale['id'].'/payments/'.$salePaymentId)
            ->assertOk()->assertJsonPath('amount_paid', '0.00');
        $this->deleteJson('/api/documents/'.$sale['id'])->assertOk();
        $this->deleteJson('/api/documents/'.$purchase['id'].'/payments/'.$purchasePaymentId)
            ->assertOk()->assertJsonPath('amount_paid', '0.00');
        $this->deleteJson('/api/documents/'.$purchase['id'])->assertOk();
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_owner_removes_installment_payments_newest_first_and_reconciles_schedule(): void
    {
        [$owner, $supplier, , $product] = $this->setupRecords(asOwner: true);
        $this->actingAs($owner);
        $purchase = $this->postJson('/api/documents', [
            ...$this->payload('purchase', $supplier, $product, '1', '100.00'),
            'payment_type' => 'installment', 'payment_method' => 'cash', 'down_payment' => 20,
            'installment_count' => 2, 'installment_frequency' => 'monthly', 'first_installment_date' => '2026-10-21',
        ])->assertCreated()->json();
        $first = $purchase['purchase_installments'][0];
        $initialId = PurchasePayment::query()->where('document_id', $purchase['id'])->firstOrFail()->id;
        $later = $this->postJson('/api/purchases/'.$purchase['id'].'/payments', [
            'purchase_installment_id' => $first['id'], 'amount' => '10.00',
            'payment_method' => 'cash', 'payment_date' => '2026-10-21',
        ])->assertCreated()->json('payment');

        $this->deleteJson('/api/documents/'.$purchase['id'].'/payments/'.$initialId)
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->deleteJson('/api/documents/'.$purchase['id'].'/payments/'.$later['id'])
            ->assertOk()->assertJsonPath('amount_paid', '20.00');
        $this->assertDatabaseHas('purchase_installments', ['id' => $first['id'], 'amount_paid' => 0]);
        $this->deleteJson('/api/documents/'.$purchase['id'].'/payments/'.$initialId)
            ->assertOk()->assertJsonPath('amount_paid', '0.00')->assertJsonPath('down_payment', '0.00');
        $this->assertDatabaseHas('purchase_installments', ['id' => $first['id'], 'amount' => 60]);
        $this->deleteJson('/api/documents/'.$purchase['id'])->assertOk();
    }

    public function test_document_deletion_rejects_staff_related_returns_and_later_stock_movements(): void
    {
        [$owner, $supplier, $customer, $product] = $this->setupRecords(asOwner: true);
        $this->actingAs($owner);
        $purchase = $this->postJson('/api/documents', $this->payload('purchase', $supplier, $product, '10', '10.00'))->assertCreated()->json();
        $sale = $this->postJson('/api/documents', $this->payload('sale', $customer, $product, '2', '15.00'))->assertCreated()->json();
        $this->deleteJson('/api/documents/'.$purchase['id'])->assertUnprocessable()->assertJsonValidationErrors('document');
        $return = $this->payload('sale_return', $customer, $product, '1', '15.00');
        $return['sale_id'] = $sale['id'];
        $this->postJson('/api/documents', $return)->assertCreated();
        $this->deleteJson('/api/documents/'.$sale['id'])->assertUnprocessable()->assertJsonValidationErrors('document');

        $staff = User::factory()->create(['organization_id' => $owner->organization_id]);
        $this->actingAs($staff)->deleteJson('/api/documents/'.$sale['id'])->assertForbidden();
        $this->assertSame('9.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('documents', ['id' => $sale['id']]);
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

    public function test_purchase_and_sale_units_convert_to_pieces_and_keep_the_entered_unit_snapshot(): void
    {
        [$user, $supplier, $customer, $product] = $this->setupRecords();
        $product->update(['unit' => 'pc']);
        $this->actingAs($user);

        $purchase = $this->payload('purchase', $supplier, $product, '2', '120.00');
        $purchase['items'][0]['unit'] = 'dozen';
        $this->postJson('/api/documents', $purchase)
            ->assertCreated()
            ->assertJsonPath('total', '240.00')
            ->assertJsonPath('items.0.quantity', '24.000')
            ->assertJsonPath('items.0.entered_quantity', '2.000')
            ->assertJsonPath('items.0.entered_unit', 'dozen')
            ->assertJsonPath('items.0.unit_conversion', 12)
            ->assertJsonPath('items.0.unit_price', '10.00');

        $sale = $this->payload('sale', $customer, $product, '2', '60.00');
        $sale['items'][0]['unit'] = 'hali';
        $firstSale = $this->postJson('/api/documents', $sale)
            ->assertCreated()
            ->assertJsonPath('total', '120.00')
            ->assertJsonPath('stock_profit', '40.00')
            ->assertJsonPath('items.0.quantity', '8.000')
            ->assertJsonPath('items.0.entered_unit', 'hali')
            ->assertJsonPath('items.0.unit_conversion', 4)
            ->json();

        $this->assertSame('16.000', $product->fresh()->quantity_on_hand);
        $this->assertSame('-8.000', StockMovement::query()->latest('id')->first()->quantity_change);

        $settings = $user->organization->fresh()->setup();
        $settings['store']['unit_conversions']['hali'] = 5;
        $user->organization->update(['system_settings' => $settings]);

        $laterSale = $this->payload('sale', $customer, $product, '1', '75.00');
        $laterSale['items'][0]['unit'] = 'hali';
        $this->postJson('/api/documents', $laterSale)
            ->assertCreated()
            ->assertJsonPath('items.0.quantity', '5.000')
            ->assertJsonPath('items.0.unit_conversion', 5);

        $this->assertSame('11.000', $product->fresh()->quantity_on_hand);
        $sales = $this->getJson('/api/documents?type=sale')->assertOk()->json();
        $this->assertSame(4, collect($sales)->firstWhere('id', $firstSale['id'])['items'][0]['unit_conversion']);
    }

    public function test_piece_product_rejects_weight_unit_without_changing_stock(): void
    {
        [$user, $supplier, , $product] = $this->setupRecords();
        $product->update(['unit' => 'pc']);
        $this->actingAs($user);
        $purchase = $this->payload('purchase', $supplier, $product, '2', '10.00');
        $purchase['items'][0]['unit'] = 'kg';

        $this->postJson('/api/documents', $purchase)->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame('0.000', $product->fresh()->quantity_on_hand);
        $this->assertSame(0, Document::query()->count());
    }

    public function test_dozen_price_keeps_exact_line_total_and_rejects_sale_above_base_stock(): void
    {
        [$user, $supplier, $customer, $product] = $this->setupRecords();
        $product->update(['unit' => 'pc']);
        $this->actingAs($user);
        $purchase = $this->payload('purchase', $supplier, $product, '1', '100.00');
        $purchase['items'][0]['unit'] = 'dozen';

        $this->postJson('/api/documents', $purchase)
            ->assertCreated()
            ->assertJsonPath('subtotal', '100.00')
            ->assertJsonPath('items.0.line_total', '100.00')
            ->assertJsonPath('items.0.unit_price', '8.33');

        $sale = $this->payload('sale', $customer, $product, '4', '40.00');
        $sale['items'][0]['unit'] = 'hali';
        $this->postJson('/api/documents', $sale)->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame('12.000', $product->fresh()->quantity_on_hand);
        $this->assertSame(1, Document::query()->count());
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
        [$user, $supplier, $customer, $product] = $this->setupRecords(asOwner: true);
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
        [$user, $supplier, $customer, $product] = $this->setupRecords(asOwner: true);
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

    public function test_dashboard_ranks_three_highest_and_lowest_products_by_branch_and_net_units_sold_this_year(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22));
        $user = User::factory()->admin()->create();
        $branch = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $otherBranch = Branch::create(['organization_id' => $user->organization_id, 'name' => 'Other Branch', 'code' => 'OTHER', 'active' => true]);
        $customer = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Buyer', 'type' => 'customer']);
        $sales = ['Alpha' => 12, 'Bravo' => 10, 'Charlie' => 8, 'Delta' => 6, 'Echo' => 4, 'Foxtrot' => 2];
        $products = [];

        foreach ($sales as $name => $quantity) {
            $products[$name] = Product::create(['organization_id' => $user->organization_id, 'name' => $name, 'unit' => 'pc', 'cost_price' => 1, 'sale_price' => 2]);
            $document = Document::create(['organization_id' => $user->organization_id, 'branch_id' => $branch->id, 'number' => 'SAL-'.$name, 'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-10-22', 'subtotal' => $quantity * 2, 'total' => $quantity * 2, 'created_by' => $user->id]);
            $document->items()->create(['product_id' => $products[$name]->id, 'quantity' => $quantity, 'unit_price' => 2, 'line_total' => $quantity * 2]);
        }

        $returned = Document::create(['organization_id' => $user->organization_id, 'branch_id' => $branch->id, 'number' => 'SRT-Bravo', 'type' => 'sale_return', 'contact_id' => $customer->id, 'document_date' => '2026-10-22', 'subtotal' => 6, 'total' => 6, 'created_by' => $user->id]);
        $returned->items()->create(['product_id' => $products['Bravo']->id, 'quantity' => 3, 'unit_price' => 2, 'line_total' => 6]);
        $otherSale = Document::create(['organization_id' => $user->organization_id, 'branch_id' => $otherBranch->id, 'number' => 'SAL-Other', 'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-10-22', 'subtotal' => 200, 'total' => 200, 'created_by' => $user->id]);
        $otherSale->items()->create(['product_id' => $products['Foxtrot']->id, 'quantity' => 100, 'unit_price' => 2, 'line_total' => 200]);
        $oldSale = Document::create(['organization_id' => $user->organization_id, 'branch_id' => $branch->id, 'number' => 'SAL-Old', 'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2025-10-22', 'subtotal' => 200, 'total' => 200, 'created_by' => $user->id]);
        $oldSale->items()->create(['product_id' => $products['Foxtrot']->id, 'quantity' => 100, 'unit_price' => 2, 'line_total' => 200]);

        $this->actingAs($user)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonCount(3, 'highest_selling_products')
            ->assertJsonCount(3, 'lowest_selling_products')
            ->assertJsonPath('highest_selling_products.0.name', 'Alpha')
            ->assertJsonPath('highest_selling_products.0.quantity', 12)
            ->assertJsonPath('highest_selling_products.1.name', 'Charlie')
            ->assertJsonPath('highest_selling_products.2.name', 'Bravo')
            ->assertJsonPath('highest_selling_products.2.quantity', 7)
            ->assertJsonPath('lowest_selling_products.0.name', 'Foxtrot')
            ->assertJsonPath('lowest_selling_products.0.quantity', 2)
            ->assertJsonPath('lowest_selling_products.1.name', 'Echo')
            ->assertJsonPath('lowest_selling_products.2.name', 'Delta');
    }

    public function test_dashboard_ranking_shows_unsold_products_without_repeating_top_sellers(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 22));
        $user = User::factory()->admin()->create();
        $branch = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $customer = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Buyer', 'type' => 'customer']);
        $products = [];

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            $products[$name] = Product::create(['organization_id' => $user->organization_id, 'name' => $name, 'unit' => 'pc', 'cost_price' => 1, 'sale_price' => 2]);
        }

        foreach (['Alpha' => 8, 'Bravo' => 2] as $name => $quantity) {
            $document = Document::create(['organization_id' => $user->organization_id, 'branch_id' => $branch->id, 'number' => 'SAL-'.$name, 'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-10-22', 'subtotal' => $quantity * 2, 'total' => $quantity * 2, 'created_by' => $user->id]);
            $document->items()->create(['product_id' => $products[$name]->id, 'quantity' => $quantity, 'unit_price' => 2, 'line_total' => $quantity * 2]);
        }

        $this->actingAs($user)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonCount(2, 'highest_selling_products')
            ->assertJsonCount(2, 'lowest_selling_products')
            ->assertJsonPath('highest_selling_products.0.name', 'Alpha')
            ->assertJsonPath('highest_selling_products.1.name', 'Bravo')
            ->assertJsonPath('lowest_selling_products.0.name', 'Charlie')
            ->assertJsonPath('lowest_selling_products.0.quantity', 0)
            ->assertJsonPath('lowest_selling_products.1.name', 'Delta')
            ->assertJsonPath('lowest_selling_products.1.quantity', 0);
    }

    public function test_product_cannot_be_created_without_purchase_cost_and_sale_price(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/products', [
            'name' => 'Price at transaction product',
            'unit' => 'pc',
            'reorder_level' => 0,
            'active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['cost_price', 'sale_price']);

        $this->assertDatabaseMissing('products', ['name' => 'Price at transaction product']);
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
        [$user, $supplier, $customer, $product] = $this->setupRecords(asOwner: true);
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
