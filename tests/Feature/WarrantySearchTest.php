<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarrantySearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_warranty_search_finds_a_sale_by_barcode_and_preserves_the_sale_term(): void
    {
        $user = User::factory()->admin()->create();
        $customer = Contact::create([
            'organization_id' => $user->organization_id,
            'name' => 'Warranty Customer',
            'type' => 'customer',
            'phone' => '01700000000',
        ]);
        $product = Product::create([
            'organization_id' => $user->organization_id,
            'name' => 'Warranty Phone',
            'barcode' => '1234567890128',
            'unit' => 'pc',
            'cost_price' => 100,
            'sale_price' => 150,
            'quantity_on_hand' => 2,
            'reorder_level' => 0,
            'warranty_months' => 12,
            'active' => true,
        ]);
        $saleDate = now()->subMonth()->toDateString();

        $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale',
            'contact_id' => $customer->id,
            'document_date' => $saleDate,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 150]],
        ])->assertCreated()->assertJsonPath('items.0.warranty_months', 12);

        $product->update(['warranty_months' => 3]);

        $this->actingAs($user)->getJson('/api/warranties?search=1234567890128')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.product.name', 'Warranty Phone')
            ->assertJsonPath('0.document.contact.name', 'Warranty Customer')
            ->assertJsonPath('0.warranty_months', 12)
            ->assertJsonPath('0.warranty_status', 'active')
            ->assertJsonPath('0.warranty_expires_at', now()->subMonth()->addMonths(12)->toDateString());
    }

    public function test_warranty_search_filters_expired_and_no_warranty_sales_without_leaking_another_company(): void
    {
        $user = User::factory()->admin()->create();
        $customer = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        $expired = Product::create([
            'organization_id' => $user->organization_id, 'name' => 'Expired product', 'unit' => 'pc',
            'quantity_on_hand' => 1, 'reorder_level' => 0, 'warranty_months' => 1, 'active' => true,
        ]);
        $withoutWarranty = Product::create([
            'organization_id' => $user->organization_id, 'name' => 'No warranty product', 'unit' => 'pc',
            'quantity_on_hand' => 1, 'reorder_level' => 0, 'active' => true,
        ]);
        foreach ([$expired, $withoutWarranty] as $product) {
            $this->actingAs($user)->postJson('/api/documents', [
                'type' => 'sale', 'contact_id' => $customer->id,
                'document_date' => now()->subMonths(2)->toDateString(),
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
            ])->assertCreated();
        }

        $foreignUser = User::factory()->admin()->create();
        $foreignCustomer = Contact::create(['organization_id' => $foreignUser->organization_id, 'name' => 'Foreign customer', 'type' => 'customer']);
        $foreignProduct = Product::create([
            'organization_id' => $foreignUser->organization_id, 'name' => 'Foreign warranty product', 'unit' => 'pc',
            'quantity_on_hand' => 1, 'reorder_level' => 0, 'warranty_months' => 12, 'active' => true,
        ]);
        $this->actingAs($foreignUser)->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $foreignCustomer->id, 'document_date' => now()->toDateString(),
            'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertCreated();

        $this->actingAs($user)->getJson('/api/warranties?status=expired')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.product.name', 'Expired product');
        $this->actingAs($user)->getJson('/api/warranties?status=no_warranty')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.product.name', 'No warranty product');
        $this->actingAs($user)->getJson('/api/warranties?search=Foreign')
            ->assertOk()->assertJsonCount(0);
    }

    public function test_warranty_search_requires_the_assigned_permission_for_staff(): void
    {
        $staff = User::factory()->staff()->create(['permissions' => []]);

        $this->actingAs($staff)->getJson('/api/warranties')->assertForbidden();

        $staff->update(['permissions' => [Permission::WarrantySearch->value]]);
        $this->actingAs($staff->fresh())->getJson('/api/warranties')->assertOk();
    }
}
