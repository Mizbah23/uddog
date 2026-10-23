<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductDamage;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDamageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function records(): array
    {
        $owner = User::factory()->admin()->create();
        $branch = Branch::query()->where('organization_id', $owner->organization_id)->where('is_default', true)->firstOrFail();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Main supplier', 'type' => 'supplier']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Glass bottle', 'unit' => 'pcs', 'cost_price' => '10.00', 'sale_price' => '15.00']);

        return [$owner, $branch, $supplier, $product];
    }

    private function purchase(Contact $supplier, Product $product, int $branchId, string $quantity = '10'): array
    {
        return $this->postJson('/api/documents', [
            'type' => 'purchase', 'branch_id' => $branchId, 'contact_id' => $supplier->id,
            'document_date' => '2026-10-19', 'discount' => 0, 'tax' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '10.00']],
        ])->assertCreated()->json();
    }

    private function damagePayload(array $purchase, Product $product, int $branchId, string $quantity = '2'): array
    {
        return [
            'branch_id' => $branchId, 'purchase_id' => $purchase['id'], 'product_id' => $product->id,
            'quantity' => $quantity, 'damaged_on' => '2026-10-20',
            'reason' => 'Broken in storage', 'notes' => 'Shelf three',
        ];
    }

    public function test_records_purchase_linked_damage_and_deducts_branch_stock(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        [$owner, $branch, $supplier, $product] = $this->records();
        $this->actingAs($owner);
        $purchase = $this->purchase($supplier, $product, $branch->id);

        $this->postJson('/api/product-damages', $this->damagePayload($purchase, $product, $branch->id))
            ->assertCreated()->assertJsonPath('purchase.number', $purchase['number'])
            ->assertJsonPath('quantity', '2.000')->assertJsonPath('reason', 'Broken in storage');

        $this->assertDatabaseHas('product_damages', ['purchase_id' => $purchase['id'], 'product_id' => $product->id, 'quantity' => 2, 'created_by' => $owner->id]);
        $this->assertDatabaseHas('product_stocks', ['branch_id' => $branch->id, 'product_id' => $product->id, 'quantity_on_hand' => 8]);
        $this->assertSame('8.000', $product->fresh()->quantity_on_hand);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'type' => 'damage', 'quantity_change' => -2, 'balance_after' => 8]);

        $this->getJson('/api/product-damages?branch_id='.$branch->id)->assertOk()
            ->assertJsonPath('data.0.purchase.number', $purchase['number'])
            ->assertJsonPath('data.0.product.name', 'Glass bottle');
        $this->getJson('/api/product-damages/timeline/'.$product->id.'?branch_id='.$branch->id)->assertOk()
            ->assertJsonPath('data.0.type', 'damage')
            ->assertJsonPath('data.0.product_damage.purchase.number', $purchase['number'])
            ->assertJsonPath('data.1.type', 'purchase');
    }

    public function test_rejects_damage_beyond_purchase_remaining_and_return_excludes_damaged_units(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        [$owner, $branch, $supplier, $product] = $this->records();
        $this->actingAs($owner);
        $purchase = $this->purchase($supplier, $product, $branch->id);
        $this->postJson('/api/product-damages', $this->damagePayload($purchase, $product, $branch->id, '3'))->assertCreated();

        $this->postJson('/api/product-damages', $this->damagePayload($purchase, $product, $branch->id, '8'))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/documents', [
            'type' => 'purchase_return', 'branch_id' => $branch->id, 'contact_id' => $supplier->id,
            'purchase_id' => $purchase['id'], 'document_date' => '2026-10-21', 'discount' => 0, 'tax' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => '8', 'unit_price' => '10.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(1, ProductDamage::query()->count());
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame('7.000', $product->fresh()->quantity_on_hand);
        $this->getJson('/api/product-damages/purchases?branch_id='.$branch->id)->assertOk()
            ->assertJsonPath('0.items.0.remaining_quantity', '7.000');

        $this->postJson('/api/documents', [
            'type' => 'purchase_return', 'branch_id' => $branch->id, 'contact_id' => $supplier->id,
            'purchase_id' => $purchase['id'], 'document_date' => '2026-10-21', 'discount' => 0, 'tax' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => '7', 'unit_price' => '10.00']],
        ])->assertCreated();
        $this->assertSame('0.000', $product->fresh()->quantity_on_hand);
        $this->getJson('/api/product-damages/purchases?branch_id='.$branch->id)->assertOk()
            ->assertJsonPath('0.items.0.remaining_quantity', '0.000');
    }

    public function test_rejects_damage_when_branch_stock_is_insufficient(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        [$owner, $branch, $supplier, $product] = $this->records();
        $this->actingAs($owner);
        $purchase = $this->purchase($supplier, $product, $branch->id, '2');
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        $this->postJson('/api/documents', [
            'type' => 'sale', 'branch_id' => $branch->id, 'contact_id' => $customer->id,
            'document_date' => '2026-10-20', 'discount' => 0, 'tax' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => '2', 'unit_price' => '15.00']],
        ])->assertCreated();

        $this->postJson('/api/product-damages', $this->damagePayload($purchase, $product, $branch->id, '1'))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(0, ProductDamage::query()->count());
        $this->assertSame('0.000', $product->fresh()->quantity_on_hand);
    }

    public function test_rejects_other_company_purchase_and_unapproved_user(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        [$owner, $branch, $supplier, $product] = $this->records();
        $this->actingAs($owner);
        $purchase = $this->purchase($supplier, $product, $branch->id);
        $otherOwner = User::factory()->admin()->create();
        $otherBranch = Branch::query()->where('organization_id', $otherOwner->organization_id)->firstOrFail();
        $payload = $this->damagePayload($purchase, $product, $otherBranch->id);
        $this->actingAs($otherOwner)->postJson('/api/product-damages', $payload)->assertNotFound();

        $staff = User::factory()->create(['organization_id' => $owner->organization_id, 'role' => 'staff', 'permissions' => []]);
        $this->actingAs($staff)->getJson('/api/product-damages')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/product-damages', $this->damagePayload($purchase, $product, $branch->id))->assertForbidden();
        $this->assertSame(0, ProductDamage::query()->count());
    }

    public function test_damage_permission_can_use_the_module_without_purchase_permission(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        [$owner, $branch, $supplier, $product] = $this->records();
        $this->actingAs($owner);
        $purchase = $this->purchase($supplier, $product, $branch->id);
        $staff = User::factory()->create(['organization_id' => $owner->organization_id, 'role' => 'staff', 'permissions' => ['product_damages']]);

        $this->actingAs($staff)->getJson('/api/products?branch_id='.$branch->id)->assertOk();
        $this->getJson('/api/product-damages/purchases?branch_id='.$branch->id)->assertOk()
            ->assertJsonPath('0.number', $purchase['number']);
        $this->postJson('/api/product-damages', $this->damagePayload($purchase, $product, $branch->id, '1'))->assertCreated();

        $this->assertSame('9.000', $product->fresh()->quantity_on_hand);
    }

    public function test_rejects_damage_before_purchase_date(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        [$owner, $branch, $supplier, $product] = $this->records();
        $this->actingAs($owner);
        $purchase = $this->purchase($supplier, $product, $branch->id);
        $payload = $this->damagePayload($purchase, $product, $branch->id);
        $payload['damaged_on'] = '2026-10-18';

        $this->postJson('/api/product-damages', $payload)->assertUnprocessable()->assertJsonValidationErrors('damaged_on');
        $this->assertSame(0, ProductDamage::query()->count());
    }
}
