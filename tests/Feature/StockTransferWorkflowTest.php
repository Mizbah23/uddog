<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_can_be_transferred_between_company_branches_with_a_complete_audit_trail(): void
    {
        $user = User::factory()->admin()->create();
        $source = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $destination = Branch::create([
            'organization_id' => $user->organization_id,
            'name' => 'Destination',
            'code' => 'DEST',
            'active' => true,
        ]);
        $product = Product::create([
            'organization_id' => $user->organization_id,
            'name' => 'Transfer item',
            'unit' => 'pc',
            'quantity_on_hand' => 5,
            'reorder_level' => 0,
            'active' => true,
        ]);
        ProductStock::create([
            'organization_id' => $user->organization_id,
            'branch_id' => $destination->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 2,
        ]);
        $product->update(['quantity_on_hand' => 7]);

        $transfer = $this->actingAs($user)->postJson('/api/stock-transfers', [
            'from_branch_id' => $source->id,
            'to_branch_id' => $destination->id,
            'transfer_date' => '2026-09-28',
            'notes' => 'Restock destination',
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated()
            ->assertJsonPath('number', 'TRF-000001')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('items.0.quantity', '3.000')
            ->json();

        $this->assertDatabaseHas('product_stocks', ['branch_id' => $source->id, 'product_id' => $product->id, 'quantity_on_hand' => 2]);
        $this->assertDatabaseHas('product_stocks', ['branch_id' => $destination->id, 'product_id' => $product->id, 'quantity_on_hand' => 5]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity_on_hand' => 7]);
        $this->assertDatabaseHas('stock_movements', [
            'stock_transfer_id' => $transfer['id'], 'branch_id' => $source->id, 'type' => 'transfer_out', 'quantity_change' => -3, 'balance_after' => 2,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'stock_transfer_id' => $transfer['id'], 'branch_id' => $destination->id, 'type' => 'transfer_in', 'quantity_change' => 3, 'balance_after' => 5,
        ]);

        $this->getJson('/api/stock-transfers?branch_id='.$source->id)->assertOk()->assertJsonCount(1);
        $this->getJson('/api/stock-transfers?branch_id='.$destination->id)->assertOk()->assertJsonCount(1);
    }

    public function test_transfer_rejects_insufficient_stock_and_rolls_back_the_record(): void
    {
        $user = User::factory()->admin()->create();
        $source = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $destination = Branch::create(['organization_id' => $user->organization_id, 'name' => 'Destination', 'code' => 'DEST', 'active' => true]);
        $product = Product::create([
            'organization_id' => $user->organization_id, 'name' => 'Limited item', 'unit' => 'pc', 'quantity_on_hand' => 1, 'reorder_level' => 0, 'active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/stock-transfers', [
            'from_branch_id' => $source->id,
            'to_branch_id' => $destination->id,
            'transfer_date' => '2026-09-28',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseHas('product_stocks', ['branch_id' => $source->id, 'product_id' => $product->id, 'quantity_on_hand' => 1]);
    }

    public function test_foreign_branches_and_products_cannot_be_transferred(): void
    {
        $user = User::factory()->admin()->create();
        $foreignUser = User::factory()->admin()->create();
        $source = Branch::query()->where('organization_id', $user->organization_id)->firstOrFail();
        $foreignBranch = Branch::query()->where('organization_id', $foreignUser->organization_id)->firstOrFail();
        $foreignProduct = Product::create([
            'organization_id' => $foreignUser->organization_id, 'name' => 'Foreign', 'unit' => 'pc', 'quantity_on_hand' => 5, 'reorder_level' => 0, 'active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/stock-transfers', [
            'from_branch_id' => $source->id,
            'to_branch_id' => $foreignBranch->id,
            'transfer_date' => '2026-09-28',
            'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('to_branch_id');
    }

    public function test_a_product_from_another_company_cannot_be_transferred_between_valid_branches(): void
    {
        $user = User::factory()->admin()->create();
        $foreignUser = User::factory()->admin()->create();
        $source = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $destination = Branch::create(['organization_id' => $user->organization_id, 'name' => 'Destination', 'code' => 'DEST', 'active' => true]);
        $foreignProduct = Product::create([
            'organization_id' => $foreignUser->organization_id, 'name' => 'Foreign', 'unit' => 'pc', 'quantity_on_hand' => 5, 'reorder_level' => 0, 'active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/stock-transfers', [
            'from_branch_id' => $source->id,
            'to_branch_id' => $destination->id,
            'transfer_date' => '2026-09-28',
            'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_stock_transfer_endpoints_require_the_stock_transfer_permission(): void
    {
        $staff = User::factory()->staff()->create(['permissions' => []]);

        $this->actingAs($staff)->getJson('/api/stock-transfers')->assertForbidden();

        $staff->update(['permissions' => [Permission::StockTransfers->value]]);
        $this->actingAs($staff->fresh())->getJson('/api/stock-transfers')->assertOk();
    }
}
