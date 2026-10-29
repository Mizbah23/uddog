<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockCheck;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SaleCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function records(): array
    {
        $owner = User::factory()->admin()->create();
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Sale correction contact', 'type' => 'both']);
        $product = Product::create([
            'organization_id' => $owner->organization_id, 'name' => 'Correction product', 'unit' => 'pcs',
            'cost_price' => '10.00', 'sale_price' => '15.00',
        ]);
        $this->actingAs($owner);
        $this->transaction($contact, $product, 'purchase', 100, 10);
        $sale = $this->transaction($contact, $product, 'sale', 45, 15);

        return [$owner, $contact, $product, $sale];
    }

    private function transaction(Contact $contact, Product $product, string $type, int $quantity, int $price, array $extra = []): array
    {
        return $this->postJson('/api/documents', [
            'type' => $type, 'contact_id' => $contact->id, 'document_date' => now('Asia/Dhaka')->toDateString(),
            'payment_type' => 'due', 'discount' => 0, 'tax' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price]],
            ...$extra,
        ])->assertCreated()->json();
    }

    private function correction(array $sale, int|float $quantity = 4, int|float $price = 15): array
    {
        return [
            'correction_version' => $sale['correction_version'], 'reason' => 'Entered 45 instead of 4 by mistake.',
            'discount' => $sale['discount'], 'tax' => $sale['tax'],
            'items' => [['id' => $sale['items'][0]['id'], 'quantity' => $quantity, 'unit_price' => $price]],
        ];
    }

    public function test_owner_corrects_45_to_4_after_later_sales_and_reconciles_invoice_stock_reports_and_history(): void
    {
        [$owner, $contact, $product, $sale] = $this->records();
        $later = $this->transaction($contact, $product, 'sale', 2, 15);
        $this->deleteJson('/api/documents/'.$sale['id'])->assertUnprocessable();

        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))
            ->assertOk()->assertJsonPath('total', '60.00')->assertJsonPath('balance_due', '60.00')
            ->assertJsonPath('total_profit', '20.00')->assertJsonPath('items.0.quantity', '4.000')
            ->assertJsonPath('items.0.entered_quantity', '4.000')->assertJsonPath('items.0.stock_quantity', '4.000')
            ->assertJsonPath('corrections.0.before.total', '675.00')->assertJsonPath('corrections.0.after.total', '60.00')
            ->assertJsonPath('corrections.0.creator.id', $owner->id)
            ->assertJsonPath('corrections.0.stock_changes.0.quantity_change', '41.000');

        $this->assertSame('94.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('product_stocks', ['product_id' => $product->id, 'branch_id' => $sale['branch_id'], 'quantity_on_hand' => 94]);
        $this->assertDatabaseHas('stock_movements', ['document_id' => $sale['id'], 'quantity_change' => -4, 'balance_after' => 96]);
        $this->assertDatabaseHas('stock_movements', ['document_id' => $later['id'], 'quantity_change' => -2, 'balance_after' => 94]);
        $this->assertDatabaseHas('documents', ['id' => $later['id'], 'total' => 30]);
        $this->getJson('/api/documents')->assertOk()->assertJsonPath('1.corrections.0.reason', 'Entered 45 instead of 4 by mistake.');
        $this->getJson('/api/reports?report=product_lifecycle_ledger&product_id='.$product->id.'&branch_id='.$sale['branch_id'])
            ->assertOk()->assertJsonPath('summary.sold_quantity', 6)->assertJsonPath('summary.sales', 90)
            ->assertJsonPath('summary.cogs', 60)->assertJsonPath('summary.closing_stock', 94);
        $this->deleteJson('/api/documents/'.$sale['id'])->assertUnprocessable()
            ->assertJsonPath('errors.document.0', 'This sale has correction history and cannot be deleted. Use another correction to fix it.');
    }

    public function test_stale_or_repeated_submission_returns_422_without_applying_stock_twice(): void
    {
        [, , $product, $sale] = $this->records();
        $payload = $this->correction($sale);
        $updated = $this->postJson('/api/documents/'.$sale['id'].'/corrections', $payload)->assertOk()->json();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $payload)->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'This sale changed after you opened it. Reopen the correction and review the latest values.');
        $this->assertSame('96.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('sale_corrections', 1);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($updated, 5))->assertOk()->assertJsonPath('total', '75.00');
        $this->assertSame('95.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('sale_corrections', 2);
    }

    public function test_partial_payments_remain_and_corrected_due_and_profit_use_new_prices_discount_and_tax(): void
    {
        [, , $product, $sale] = $this->records();
        $this->postJson('/api/documents/'.$sale['id'].'/payments', ['amount' => 20, 'payment_method' => 'cash'])->assertCreated();
        $sale = Document::with('items.product')->findOrFail($sale['id'])->toArray();
        $payload = [...$this->correction($sale, 4, 20), 'discount' => 5, 'tax' => 2];
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $payload)->assertOk()
            ->assertJsonPath('subtotal', '80.00')->assertJsonPath('total', '77.00')
            ->assertJsonPath('amount_paid', '20.00')->assertJsonPath('balance_due', '57.00')
            ->assertJsonPath('total_profit', '35.00')->assertJsonCount(1, 'payments');
        $this->assertSame('96.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('sale_payments', ['document_id' => $sale['id'], 'amount' => 20]);
    }

    public function test_excess_payments_block_correction_without_changing_stock_or_invoice(): void
    {
        [, , $product, $sale] = $this->records();
        $this->postJson('/api/documents/'.$sale['id'].'/payments', ['amount' => 100, 'payment_method' => 'cash'])->assertCreated();
        $sale = Document::with('items.product')->findOrFail($sale['id'])->toArray();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'Payments exceed the corrected total. Remove the excess related payments before correcting this sale.');
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('documents', ['id' => $sale['id'], 'total' => 675, 'amount_paid' => 100]);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_increased_quantity_cannot_make_later_historical_stock_negative_even_if_current_stock_is_available(): void
    {
        [, $contact, $product, $sale] = $this->records();
        $this->transaction($contact, $product, 'sale', 50, 15);
        $this->transaction($contact, $product, 'purchase', 100, 10);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale, 60))->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'The correction would make a later stock balance negative or exceed the supported range.');
        $this->assertSame('105.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('stock_movements', ['document_id' => $sale['id'], 'quantity_change' => -45, 'balance_after' => 55]);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_current_stock_cannot_become_negative(): void
    {
        [, , $product, $sale] = $this->records();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale, 101))->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'The correction would make stock negative or exceed the supported range.');
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_later_stock_count_blocks_quantity_change_but_allows_price_correction(): void
    {
        [$owner, , $product, $sale] = $this->records();
        $check = StockCheck::create(['organization_id' => $owner->organization_id, 'branch_id' => $sale['branch_id'], 'number' => 'CHK-1', 'check_date' => now()->toDateString(), 'status' => 'draft', 'created_by' => $owner->id]);
        $check->items()->create(['product_id' => $product->id, 'expected_quantity' => 55, 'counted_quantity' => 55]);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'A later physical stock check exists for this product. Reconcile that count before correcting the quantity.');
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale, 45, 14))->assertOk()->assertJsonPath('total', '630.00');
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
    }

    public function test_linked_returns_block_correction(): void
    {
        [, $contact, $product, $sale] = $this->records();
        $this->transaction($contact, $product, 'sale_return', 1, 15, ['sale_id' => $sale['id']]);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'This sale has linked returns. Resolve those returns before correcting it.');
        $this->assertSame('56.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    #[TestWith(['installment'])]
    #[TestWith(['gold'])]
    public function test_special_sales_require_separate_reconciliation(string $kind): void
    {
        [, , $product, $sale] = $this->records();
        if ($kind === 'installment') {
            Document::findOrFail($sale['id'])->update(['payment_type' => 'installment']);
        } else {
            $product->update(['is_gold_item' => true]);
        }
        $sale = Document::with('items.product')->findOrFail($sale['id'])->toArray();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'Installment and gold sales require separate reconciliation and cannot be corrected here.');
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_converted_units_and_shared_stock_use_the_original_sale_mapping(): void
    {
        [$owner, $contact, $stockProduct] = $this->records();
        $owner->organization->update(['shared_stock_sales' => true]);
        $variant = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Shared product', 'unit' => 'pcs', 'cost_price' => 0, 'sale_price' => 30, 'stock_source_product_id' => $stockProduct->id, 'stock_source_quantity' => '2.000']);
        $sale = $this->transaction($contact, $variant, 'sale', 2, 360, ['items' => [['product_id' => $variant->id, 'quantity' => 2, 'unit' => 'dozen', 'unit_price' => 360]]]);
        $variant->update(['stock_source_quantity' => 3]);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale, 1, 360))->assertOk()
            ->assertJsonPath('items.0.entered_quantity', '1.000')->assertJsonPath('items.0.quantity', '12.000')
            ->assertJsonPath('items.0.stock_quantity', '24.000')->assertJsonPath('total', '360.00');
        $this->assertSame('31.000', $stockProduct->fresh()->quantity_on_hand);
        $this->assertSame('0.000', $variant->fresh()->quantity_on_hand);
    }

    public function test_multiple_sale_lines_sharing_stock_reconcile_each_movement_once(): void
    {
        [$owner, $contact, $product] = $this->records();
        $owner->organization->update(['shared_stock_sales' => true]);
        $variant = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Second product', 'unit' => 'pcs', 'cost_price' => 0, 'sale_price' => 15, 'stock_source_product_id' => $product->id, 'stock_source_quantity' => 1]);
        $sale = $this->transaction($contact, $product, 'sale', 10, 15, ['items' => [
            ['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 15],
            ['product_id' => $variant->id, 'quantity' => 20, 'unit_price' => 15],
        ]]);
        $payload = $this->correction($sale, 1);
        $payload['items'][] = ['id' => $sale['items'][1]['id'], 'quantity' => 2, 'unit_price' => 15];
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $payload)->assertOk()->assertJsonPath('total', '45.00');
        $this->assertSame('52.000', $product->fresh()->quantity_on_hand);
        $this->assertSame(['54.000', '52.000'], StockMovement::where('document_id', $sale['id'])->orderBy('id')->pluck('balance_after')->all());
    }

    public function test_correction_requires_admin_and_does_not_expose_other_organizations_or_branches(): void
    {
        [$owner, , $product, $sale] = $this->records();
        $staff = User::factory()->create(['organization_id' => $owner->organization_id]);
        $this->actingAs($staff)->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertForbidden();
        $otherOwner = User::factory()->admin()->create();
        $this->actingAs($otherOwner)->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertNotFound();
        $branch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Other branch', 'code' => 'OTHER', 'active' => true]);
        $this->actingAs($owner)->postJson('/api/documents/'.$sale['id'].'/corrections?branch_id='.$branch->id, $this->correction($sale))->assertNotFound();
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    #[TestWith(['reason', ''])]
    #[TestWith(['items.0.quantity', 0])]
    #[TestWith(['items.0.quantity', 4.5])]
    #[TestWith(['items.0.unit_price', -1])]
    #[TestWith(['items.0.id', 999999])]
    #[TestWith(['discount', 1000])]
    public function test_invalid_corrections_leave_all_records_unchanged(string $field, string|int|float $value): void
    {
        [, , $product, $sale] = $this->records();
        $payload = $this->correction($sale);
        data_set($payload, $field, $value);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $payload)->assertUnprocessable();
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('documents', ['id' => $sale['id'], 'total' => 675]);
        $this->assertDatabaseHas('document_items', ['id' => $sale['items'][0]['id'], 'quantity' => 45]);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_missing_stock_history_is_rejected_without_writing_a_partial_correction(): void
    {
        [, , $product, $sale] = $this->records();
        StockMovement::where('document_id', $sale['id'])->delete();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'The stock history for this sale needs review before correction.');
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_no_change_is_rejected_without_creating_an_audit_record(): void
    {
        [, , , $sale] = $this->records();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale, 45))->assertUnprocessable()
            ->assertJsonPath('errors.correction.0', 'Change at least one quantity, price, discount or tax before saving a correction.');
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_a_failure_on_the_second_stock_product_rolls_back_the_first_product_and_movements(): void
    {
        [$owner, $contact, $first] = $this->records();
        $second = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Second stock product', 'unit' => 'pcs', 'cost_price' => 10, 'sale_price' => 15]);
        $this->transaction($contact, $second, 'purchase', 10, 10);
        $sale = $this->transaction($contact, $first, 'sale', 5, 15, ['items' => [
            ['product_id' => $first->id, 'quantity' => 5, 'unit_price' => 15],
            ['product_id' => $second->id, 'quantity' => 5, 'unit_price' => 15],
        ]]);
        $payload = $this->correction($sale, 1);
        $payload['items'][] = ['id' => $sale['items'][1]['id'], 'quantity' => 11, 'unit_price' => 15];
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $payload)->assertUnprocessable();
        $this->assertSame('50.000', $first->fresh()->quantity_on_hand);
        $this->assertSame('5.000', $second->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('stock_movements', ['document_id' => $sale['id'], 'product_id' => $first->id, 'quantity_change' => -5, 'balance_after' => 50]);
        $this->assertDatabaseHas('documents', ['id' => $sale['id'], 'total' => 150]);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_correcting_a_paid_sale_to_a_higher_price_preserves_payments_and_marks_the_remaining_due(): void
    {
        [, $contact, $product] = $this->records();
        $sale = $this->transaction($contact, $product, 'sale', 4, 15, ['payment_type' => 'full', 'payment_method' => 'cash']);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale, 4, 20))->assertOk()
            ->assertJsonPath('total', '80.00')->assertJsonPath('amount_paid', '60.00')
            ->assertJsonPath('balance_due', '20.00')->assertJsonPath('payment_type', 'due')->assertJsonCount(1, 'payments');
        $this->assertSame('51.000', $product->fresh()->quantity_on_hand);
    }

    public function test_other_branch_stock_and_movements_are_preserved(): void
    {
        [$owner, $contact, $product, $sale] = $this->records();
        $branch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Unaffected branch', 'code' => 'UNAFFECTED', 'active' => true]);
        $this->transaction($contact, $product, 'purchase', 10, 10, ['branch_id' => $branch->id]);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertOk();
        $this->assertSame('106.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('product_stocks', ['product_id' => $product->id, 'branch_id' => $branch->id, 'quantity_on_hand' => 10]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'branch_id' => $branch->id, 'quantity_change' => 10, 'balance_after' => 10]);
    }

    public function test_stock_value_restores_the_original_cost_after_a_later_purchase_at_another_price(): void
    {
        [, $contact, $product, $sale] = $this->records();
        $this->transaction($contact, $product, 'purchase', 10, 20);
        $this->assertSame('11.54', $product->fresh()->cost_price);
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', $this->correction($sale))->assertOk()
            ->assertJsonPath('corrections.0.stock_changes.0.cost_price_before', '11.54')
            ->assertJsonPath('corrections.0.stock_changes.0.cost_price_after', '10.94');
        $this->assertSame('106.000', $product->fresh()->quantity_on_hand);
        $this->assertSame('10.94', $product->fresh()->cost_price);
    }

    #[TestWith([0, '45.00'])]
    #[TestWith([45, '0.00'])]
    public function test_legacy_paid_total_without_payment_entries_can_be_corrected_with_quantity_and_audit_history(int $actuallyPaid, string $due): void
    {
        [, , $product, $sale] = $this->records();
        $legacy = Document::findOrFail($sale['id']);
        $legacy->update(['payment_type' => null, 'subtotal' => '506.25', 'total' => '506.25', 'amount_paid' => '506.25', 'down_payment' => '506.25']);
        $legacy->items()->firstOrFail()->update(['unit_price' => '11.25', 'entered_unit_price' => '11.25', 'line_total' => '506.25']);
        $sale = $legacy->fresh()->load('items.product', 'payments')->toArray();

        $this->postJson('/api/documents/'.$sale['id'].'/corrections', [
            ...$this->correction($sale, 4, 11.25), 'recorded_amount_paid' => $actuallyPaid,
        ])->assertOk()->assertJsonPath('total', '45.00')->assertJsonPath('balance_due', $due)
            ->assertJsonPath('amount_paid', number_format($actuallyPaid, 2, '.', ''))
            ->assertJsonPath('down_payment', number_format($actuallyPaid, 2, '.', ''))
            ->assertJsonPath('corrections.0.before.amount_paid', '506.25')
            ->assertJsonPath('corrections.0.before.down_payment', '506.25')
            ->assertJsonPath('corrections.0.after.amount_paid', number_format($actuallyPaid, 2, '.', ''))
            ->assertJsonCount(0, 'payments');
        $this->assertSame('96.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertDatabaseCount('sale_corrections', 1);
    }

    public function test_recorded_paid_total_cannot_override_individual_payment_entries(): void
    {
        [, , $product, $sale] = $this->records();
        $this->postJson('/api/documents/'.$sale['id'].'/payments', ['amount' => 20, 'payment_method' => 'cash'])->assertCreated();
        $sale = Document::with('items.product')->findOrFail($sale['id'])->toArray();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', [
            ...$this->correction($sale), 'recorded_amount_paid' => 0,
        ])->assertUnprocessable()->assertJsonPath('errors.correction.0', 'Only a recorded paid total without individual payment entries can be reduced here. Manage individual payments from the invoice.');
        $this->assertDatabaseHas('documents', ['id' => $sale['id'], 'amount_paid' => 20, 'total' => 675]);
        $this->assertDatabaseHas('sale_payments', ['document_id' => $sale['id'], 'amount' => 20]);
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    #[TestWith([-1])]
    #[TestWith([700])]
    public function test_invalid_recorded_paid_adjustments_leave_the_invoice_and_stock_unchanged(int $paid): void
    {
        [, , $product, $sale] = $this->records();
        Document::findOrFail($sale['id'])->update(['amount_paid' => 675]);
        $sale = Document::with('items.product')->findOrFail($sale['id'])->toArray();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', [
            ...$this->correction($sale), 'recorded_amount_paid' => $paid,
        ])->assertUnprocessable();
        $this->assertSame('55.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('documents', ['id' => $sale['id'], 'amount_paid' => 675, 'total' => 675]);
        $this->assertDatabaseCount('sale_corrections', 0);
    }

    public function test_recorded_paid_total_cannot_add_a_payment_to_an_unpaid_sale(): void
    {
        [, , , $sale] = $this->records();
        $this->postJson('/api/documents/'.$sale['id'].'/corrections', [
            ...$this->correction($sale), 'recorded_amount_paid' => 10,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('documents', ['id' => $sale['id'], 'amount_paid' => 0, 'total' => 675]);
        $this->assertDatabaseCount('sale_corrections', 0);
    }
}
