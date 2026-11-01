<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginated_catalog_is_bounded_and_stably_ordered_with_company_isolation(): void
    {
        $owner = User::factory()->admin()->create();
        $ids = [];
        for ($i = 0; $i < 53; $i++) {
            $ids[] = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Same product name', 'unit' => 'pc'])->id;
        }
        $other = User::factory()->admin()->create();
        Product::create(['organization_id' => $other->organization_id, 'name' => 'Same product name', 'unit' => 'pc']);
        $this->actingAs($owner)->getJson('/api/products?paginate=1')->assertOk()
            ->assertJsonCount(25, 'data')->assertJsonPath('pagination.total', 53)
            ->assertJsonPath('pagination.last_page', 3)->assertJsonPath('data.0.id', $ids[0])
            ->assertJsonPath('data.24.id', $ids[24]);
        $this->getJson('/api/products?paginate=1&page=2')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('data.0.id', $ids[25]);
        $this->getJson('/api/products?paginate=1&page=3')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.id', $ids[50]);
        $this->getJson('/api/products?paginate=1&per_page=10000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_catalog_search_uses_name_sku_barcode_and_category_before_pagination(): void
    {
        $owner = User::factory()->admin()->create();
        $category = Category::create(['organization_id' => $owner->organization_id, 'name' => 'Stationery']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Notebook', 'sku' => 'SKU-PAPER', 'barcode' => '8901234567890', 'category_id' => $category->id, 'unit' => 'pc']);
        Product::create(['organization_id' => $owner->organization_id, 'name' => 'Unrelated', 'unit' => 'pc']);
        $this->actingAs($owner);
        foreach (['Notebook', 'SKU-PAPER', '8901234567890', 'Stationery'] as $search) {
            $this->getJson('/api/products?paginate=1&search='.$search)->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $product->id);
        }
        $this->getJson('/api/products?paginate=1&search=missing')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 0);
    }

    public function test_paginated_catalog_uses_selected_branch_stock_even_when_shared_source_is_on_another_page(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Second', 'code' => 'SECOND', 'active' => true]);
        $source = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Z source', 'unit' => 'pc', 'quantity_on_hand' => 100]);
        $variant = Product::create(['organization_id' => $owner->organization_id, 'name' => 'A variant', 'unit' => 'pc', 'stock_source_product_id' => $source->id, 'stock_source_quantity' => 2]);
        ProductStock::create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'product_id' => $source->id, 'quantity_on_hand' => 8]);
        $this->actingAs($owner)->getJson('/api/products?paginate=1&per_page=1&branch_id='.$branch->id)->assertOk()
            ->assertJsonPath('data.0.id', $variant->id)->assertJsonPath('data.0.source_quantity_on_hand', '8.000')
            ->assertJsonPath('data.0.available_quantity', '4.000')->assertJsonPath('pagination.total', 2);
    }

    private function productData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sample product',
            'sku' => '',
            'category' => '',
            'unit' => 'pc',
            'cost_price' => '12.00',
            'sale_price' => '15.00',
            'reorder_level' => '2',
            'active' => true,
        ], $overrides);
    }

    public function test_multiple_products_can_be_created_without_skus(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/products', $this->productData())->assertCreated()->assertJsonPath('sku', null);
        $this->postJson('/api/products', $this->productData(['name' => 'Second product', 'unit' => 'kg']))
            ->assertCreated()->assertJsonPath('unit', 'kg')->assertJsonPath('sku', null);

        $this->assertSame(2, Product::query()->whereNull('sku')->count());
    }

    public function test_entered_sku_remains_unique_and_can_be_cleared(): void
    {
        $this->actingAs(User::factory()->create());

        $product = $this->postJson('/api/products', $this->productData(['sku' => 'ABC-1']))->assertCreated()->json();
        $this->postJson('/api/products', $this->productData(['name' => 'Second product', 'sku' => 'ABC-1']))
            ->assertUnprocessable()->assertJsonValidationErrors('sku');

        $this->putJson('/api/products/'.$product['id'], $this->productData(['sku' => '']))
            ->assertOk()->assertJsonPath('sku', null);
    }

    public function test_ean13_barcode_is_validated_and_unique_inside_the_company(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/products', $this->productData(['barcode' => '6291041500213']))
            ->assertCreated()
            ->assertJsonPath('barcode', '6291041500213');
        $this->postJson('/api/products', $this->productData(['name' => 'Duplicate barcode', 'barcode' => '6291041500213']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('barcode');
        $this->postJson('/api/products', $this->productData(['name' => 'Invalid barcode', 'barcode' => 'ABC-123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('barcode');
    }

    public function test_unit_must_be_one_of_the_dropdown_choices(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/products', $this->productData(['unit' => 'bucket']))
            ->assertUnprocessable()->assertJsonValidationErrors('unit');
    }

    public function test_new_product_defaults_to_one_piece_when_unit_is_not_sent(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJson('/api/products', array_diff_key($this->productData(), ['unit' => true]))
            ->assertCreated()
            ->assertJsonPath('unit', 'pc');

        $this->assertDatabaseHas('products', ['id' => $response->json('id'), 'unit' => 'pc']);
    }

    public function test_purchase_cost_and_sale_price_are_required_when_updating_a_product(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->postJson('/api/products', $this->productData())->assertCreated()->json();

        $this->putJson('/api/products/'.$product['id'], $this->productData([
            'cost_price' => '',
            'sale_price' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['cost_price', 'sale_price']);

        $this->assertDatabaseHas('products', [
            'id' => $product['id'],
            'cost_price' => '12.00',
            'sale_price' => '15.00',
        ]);
    }
}
