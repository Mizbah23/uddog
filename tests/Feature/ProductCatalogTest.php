<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

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
}
