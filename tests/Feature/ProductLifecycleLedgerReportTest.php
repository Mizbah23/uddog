<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductLifecycleLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_lots_partial_sale_and_returns_reconcile_with_period_opening_stock(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Ledger contact', 'type' => 'both']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Ledger product', 'sku' => 'LEDGER-1', 'cost_price' => 12, 'sale_price' => 20]);

        $this->transaction($owner, $branch->id, $contact->id, $product, 'purchase', '2026-10-01', 10, 10, 10);
        $second = $this->transaction($owner, $branch->id, $contact->id, $product, 'purchase', '2026-10-02', 5, 12, 15);
        $sale = $this->transaction($owner, $branch->id, $contact->id, $product, 'sale', '2026-10-03', 12, 20, 3);
        $return = $this->transaction($owner, $branch->id, $contact->id, $product, 'sale_return', '2026-10-04', 2, 20, 5, ['sale_id' => $sale->id]);
        $purchaseReturn = $this->transaction($owner, $branch->id, $contact->id, $product, 'purchase_return', '2026-10-05', 1, 12, 4, ['purchase_id' => $second->id]);
        $this->transaction($owner, $branch->id, $contact->id, $product, 'sale', '2026-10-06', 1, 22, 3);
        $product->stocks()->where('branch_id', $branch->id)->update(['quantity_on_hand' => 3]);

        $this->actingAs($owner)->getJson('/api/reports?report=product_lifecycle_ledger&product_id='.$product->id.'&branch_id='.$branch->id.'&date_from=2026-10-03&date_to=2026-10-05')
            ->assertOk()
            ->assertJsonCount(3, 'rows')
            ->assertJsonPath('summary.opening_stock', 15)
            ->assertJsonPath('summary.opening_value', 160)
            ->assertJsonPath('summary.sold_quantity', 10)
            ->assertJsonPath('summary.sales', 200)
            ->assertJsonPath('summary.cogs', 104)
            ->assertJsonPath('summary.realized_profit', 96)
            ->assertJsonPath('summary.closing_stock', 4)
            ->assertJsonPath('summary.closing_value', 44)
            ->assertJsonPath('rows.0.reference', $sale->number)
            ->assertJsonPath('rows.0.cogs', 124)
            ->assertJsonPath('rows.0.profit', 116)
            ->assertJsonPath('rows.0.allocations.0.quantity', 10)
            ->assertJsonPath('rows.0.allocations.0.cost', 100)
            ->assertJsonPath('rows.0.allocations.0.revenue', 200)
            ->assertJsonPath('rows.0.allocations.0.profit', 100)
            ->assertJsonPath('rows.0.allocations.1.quantity', 2)
            ->assertJsonPath('rows.0.allocations.1.cost', 24)
            ->assertJsonPath('rows.0.allocations.1.revenue', 40)
            ->assertJsonPath('rows.0.allocations.1.profit', 16)
            ->assertJsonPath('rows.1.reference', $return->number)
            ->assertJsonPath('rows.1.cogs', -20)
            ->assertJsonPath('rows.2.reference', $purchaseReturn->number)
            ->assertJsonPath('rows.2.stock_value', 44);
    }

    public function test_purchase_and_selling_prices_compare_in_chronological_order(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Ledger contact', 'type' => 'both']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Price history', 'sku' => 'LEDGER-2', 'cost_price' => 8]);

        $this->transaction($owner, $branch->id, $contact->id, $product, 'purchase', '2026-10-01', 4, 10, 4);
        $this->transaction($owner, $branch->id, $contact->id, $product, 'purchase', '2026-10-02', 4, 8, 8);
        $this->transaction($owner, $branch->id, $contact->id, $product, 'sale', '2026-10-03', 2, 20, 6);
        $this->transaction($owner, $branch->id, $contact->id, $product, 'sale', '2026-10-04', 2, 25, 4);
        $product->stocks()->where('branch_id', $branch->id)->update(['quantity_on_hand' => 4]);

        $this->actingAs($owner)->getJson('/api/reports?report=product_lifecycle_ledger&product_id='.$product->id.'&branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('rows.1.price_change', -2)
            ->assertJsonPath('rows.1.price_change_percent', -20)
            ->assertJsonPath('rows.3.price_change', 5)
            ->assertJsonPath('rows.3.price_change_percent', 25)
            ->assertJsonPath('rows.3.stock_balance', 4)
            ->assertJsonPath('summary.closing_value', 32);
    }

    public function test_permission_and_tenant_boundaries(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Restricted', 'sku' => 'LEDGER-3']);
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Sales->value],
            'report_permissions' => ['product_lifecycle_ledger'],
        ]);
        $this->actingAs($staff)->getJson('/api/reports?report=product_lifecycle_ledger&product_id='.$product->id.'&branch_id='.$branch->id)->assertForbidden();

        $otherOwner = User::factory()->admin()->create();
        $this->actingAs($otherOwner)->getJson('/api/reports?report=product_lifecycle_ledger&product_id='.$product->id)->assertNotFound();
    }

    public function test_a_destination_branch_inherits_the_source_lots_fifo_cost_on_transfer(): void
    {
        $owner = User::factory()->admin()->create();
        $main = $owner->organization->branches()->firstOrFail();
        $other = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Other branch', 'code' => 'OTHER', 'active' => true]);
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Ledger contact', 'type' => 'both']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Transferred', 'sku' => 'LEDGER-4', 'cost_price' => 12]);
        $this->transaction($owner, $main->id, $contact->id, $product, 'purchase', '2026-10-01', 5, 10, 5);
        $transfer = StockTransfer::create([
            'organization_id' => $owner->organization_id, 'from_branch_id' => $main->id,
            'to_branch_id' => $other->id, 'number' => 'TRF-LEDGER', 'transfer_date' => '2026-10-02', 'created_by' => $owner->id,
        ]);
        foreach ([[$main->id, 'transfer_out', -3, 2], [$other->id, 'transfer_in', 3, 3]] as [$branchId, $type, $change, $balance]) {
            $movement = StockMovement::create([
                'organization_id' => $owner->organization_id, 'branch_id' => $branchId,
                'product_id' => $product->id, 'stock_transfer_id' => $transfer->id,
                'type' => $type, 'quantity_change' => $change, 'balance_after' => $balance,
                'created_by' => $owner->id,
            ]);
            $movement->forceFill(['created_at' => '2026-10-02 10:00:00'])->save();
        }
        $this->transaction($owner, $other->id, $contact->id, $product, 'sale', '2026-10-03', 2, 20, 1);
        $product->stocks()->where('branch_id', $main->id)->update(['quantity_on_hand' => 2]);
        $product->stocks()->create(['organization_id' => $owner->organization_id, 'branch_id' => $other->id, 'quantity_on_hand' => 1]);

        $this->actingAs($owner)->getJson('/api/reports?report=product_lifecycle_ledger&product_id='.$product->id.'&branch_id='.$other->id)
            ->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.0.type', 'transfer_in')
            ->assertJsonPath('rows.0.stock_value', 30)
            ->assertJsonPath('rows.1.cogs', 20)
            ->assertJsonPath('rows.1.profit', 20)
            ->assertJsonPath('summary.closing_stock', 1)
            ->assertJsonPath('summary.closing_value', 10)
            ->assertJsonPath('summary.sales', 40);
    }

    private function transaction(User $owner, int $branchId, int $contactId, Product $product, string $type, string $date, int $quantity, int $price, int $balance, array $links = []): Document
    {
        $document = Document::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branchId,
            'number' => strtoupper($type).'-'.uniqid(), 'type' => $type,
            'contact_id' => $contactId, 'document_date' => $date,
            'subtotal' => $quantity * $price, 'total' => $quantity * $price,
            'created_by' => $owner->id, ...$links,
        ]);
        $document->items()->create([
            'product_id' => $product->id, 'quantity' => $quantity,
            'unit_price' => $price, 'cost_price' => $product->cost_price,
            'line_total' => $quantity * $price,
        ]);
        StockMovement::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branchId,
            'product_id' => $product->id, 'document_id' => $document->id,
            'type' => $type, 'quantity_change' => in_array($type, ['purchase', 'sale_return'], true) ? $quantity : -$quantity,
            'balance_after' => $balance, 'created_by' => $owner->id,
        ]);

        return $document;
    }
}
