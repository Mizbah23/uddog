<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_reads_and_writes_are_isolated_by_client(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $userA = User::factory()->for($organizationA)->create();
        $productA = Product::create($this->productData($organizationA->id, 'A product'));
        $productB = Product::create($this->productData($organizationB->id, 'B product'));

        $this->actingAs($userA)->getJson('/api/products')
            ->assertOk()
            ->assertJsonFragment(['id' => $productA->id])
            ->assertJsonMissing(['id' => $productB->id]);

        $this->actingAs($userA)->putJson("/api/products/{$productB->id}", $this->productPayload('Changed'))
            ->assertNotFound();
        $this->actingAs($userA)->postJson("/api/products/{$productB->id}/adjust", [
            'quantity_change' => 1,
            'notes' => 'Should not work',
        ])->assertNotFound();

        $this->assertSame('B product', $productB->fresh()->name);
    }

    public function test_documents_reject_contacts_and_products_from_another_client(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $userA = User::factory()->for($organizationA)->create();
        $supplierB = Contact::create(['organization_id' => $organizationB->id, 'name' => 'B supplier', 'type' => 'supplier']);
        $productB = Product::create($this->productData($organizationB->id, 'B product'));

        $this->actingAs($userA)->postJson('/api/documents', [
            'type' => 'purchase',
            'contact_id' => $supplierB->id,
            'document_date' => now()->toDateString(),
            'items' => [['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['contact_id', 'items.0.product_id']);
    }

    public function test_sku_is_unique_inside_a_client_but_reusable_by_another_client(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        Product::create($this->productData($organizationA->id, 'A product', 'SHARED-1'));
        $userB = User::factory()->for($organizationB)->create();

        $this->actingAs($userB)->postJson('/api/products', $this->productPayload('B product', 'SHARED-1'))
            ->assertCreated();
        $this->actingAs($userB)->postJson('/api/products', $this->productPayload('B duplicate', 'SHARED-1'))
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    private function productData(int $organizationId, string $name, ?string $sku = null): array
    {
        return ['organization_id' => $organizationId, ...$this->productPayload($name, $sku)];
    }

    private function productPayload(string $name, ?string $sku = null): array
    {
        return [
            'name' => $name,
            'sku' => $sku,
            'category' => null,
            'unit' => 'pc',
            'cost_price' => '10.00',
            'sale_price' => '12.00',
            'reorder_level' => '1',
            'active' => true,
        ];
    }
}
