<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCheckWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stock_check_can_be_saved_and_completed_to_reconcile_branch_stock(): void
    {
        $user = User::factory()->admin()->create();
        $branch = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $product = Product::create([
            'organization_id' => $user->organization_id,
            'name' => 'Counted product',
            'unit' => 'pc',
            'quantity_on_hand' => 5,
            'reorder_level' => 0,
            'active' => true,
        ]);

        $this->actingAs($user);

        $check = $this->postJson('/api/stock-checks', [
            'branch_id' => $branch->id,
            'check_date' => '2026-09-27',
            'notes' => 'Monthly physical count',
        ])->assertCreated()
            ->assertJsonPath('number', 'CHK-000001')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('items.0.expected_quantity', '5.000')
            ->json();

        $this->putJson('/api/stock-checks/'.$check['id'], [
            'branch_id' => $branch->id,
            'check_date' => '2026-09-27',
            'notes' => 'Shelf count complete',
            'items' => [[
                'id' => $check['items'][0]['id'],
                'counted_quantity' => 3,
            ]],
        ])->assertOk()
            ->assertJsonPath('items.0.variance', '-2.000');

        $this->postJson('/api/stock-checks/'.$check['id'].'/complete', [
            'branch_id' => $branch->id,
        ])->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('items.0.counted_quantity', '3.000');

        $this->assertDatabaseHas('product_stocks', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 3,
        ]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity_on_hand' => 3]);
        $this->assertDatabaseHas('stock_movements', [
            'organization_id' => $user->organization_id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'stock_check_id' => $check['id'],
            'type' => 'stock_check',
            'quantity_change' => -2,
            'balance_after' => 3,
        ]);

        $this->putJson('/api/stock-checks/'.$check['id'], [
            'branch_id' => $branch->id,
            'check_date' => '2026-09-27',
            'items' => [['id' => $check['items'][0]['id'], 'counted_quantity' => 4]],
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_stock_checks_are_branch_specific_and_require_every_product_to_be_counted(): void
    {
        $user = User::factory()->admin()->create();
        $mainBranch = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $secondBranch = Branch::create([
            'organization_id' => $user->organization_id,
            'name' => 'Second Branch',
            'code' => 'SECOND',
            'active' => true,
        ]);
        $product = Product::create([
            'organization_id' => $user->organization_id,
            'name' => 'Branch product',
            'unit' => 'pc',
            'quantity_on_hand' => 7,
            'reorder_level' => 0,
            'active' => true,
        ]);
        ProductStock::create([
            'organization_id' => $user->organization_id,
            'branch_id' => $secondBranch->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 2,
        ]);
        $product->update(['quantity_on_hand' => 9]);

        $this->actingAs($user);
        $check = $this->postJson('/api/stock-checks', [
            'branch_id' => $secondBranch->id,
            'check_date' => '2026-09-27',
        ])->assertCreated()
            ->assertJsonPath('items.0.expected_quantity', '2.000')
            ->json();

        $this->postJson('/api/stock-checks/'.$check['id'].'/complete', [
            'branch_id' => $secondBranch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->getJson('/api/stock-checks?branch_id='.$mainBranch->id)
            ->assertOk()->assertJsonCount(0);
        $this->getJson('/api/stock-checks?branch_id='.$secondBranch->id)
            ->assertOk()->assertJsonCount(1);
    }

    public function test_only_one_draft_stock_check_is_allowed_per_branch(): void
    {
        $user = User::factory()->admin()->create();
        $branch = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        Product::create([
            'organization_id' => $user->organization_id,
            'name' => 'Product',
            'unit' => 'pc',
            'quantity_on_hand' => 1,
            'reorder_level' => 0,
            'active' => true,
        ]);

        $payload = ['branch_id' => $branch->id, 'check_date' => '2026-09-27'];
        $this->actingAs($user)->postJson('/api/stock-checks', $payload)->assertCreated();
        $this->postJson('/api/stock-checks', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame(0, StockMovement::query()->count());
    }
}
