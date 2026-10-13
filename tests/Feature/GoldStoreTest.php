<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\GoldRate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoldStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_gold_store_rates_and_gold_weight_pricing_are_company_scoped(): void
    {
        $user = User::factory()->create();
        $organization = $user->organization;
        $organization->update(['gold_store' => true]);
        $branch = $organization->branches()->firstOrFail();
        $customer = Contact::create(['organization_id' => $organization->id, 'name' => 'Gold customer', 'type' => 'customer']);
        $supplier = Contact::create(['organization_id' => $organization->id, 'name' => 'Gold supplier', 'type' => 'supplier']);
        $product = Product::create([
            'organization_id' => $organization->id, 'name' => 'Gold ring', 'unit' => 'pc', 'active' => true,
            'is_gold_item' => true, 'gold_purity' => '22K', 'gold_gross_weight' => 10,
            'gold_stone_weight' => 0, 'gold_other_weight' => 0, 'making_charge_mode' => 'flat',
            'making_charge' => 50, 'wastage_percent' => 0, 'stone_value' => 0,
        ]);

        $this->actingAs($user)->postJson('/api/gold/rates', [
            'branch_id' => $branch->id, 'metal' => 'gold', 'purity' => '22k',
            'rate_per_gram' => 100, 'effective_on' => '2026-10-07',
        ])->assertCreated();

        $this->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => '2026-10-07',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 700]],
        ])->assertCreated();

        $sale = $this->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => '2026-10-07',
            'payment_type' => 'due', 'gold_exchange_weight' => 2, 'gold_exchange_purity' => '22K',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 0]],
        ])->assertCreated()
            ->assertJsonPath('total', '1050.00')
            ->assertJsonPath('gold_exchange_value', '200.00')
            ->assertJsonPath('amount_paid', '200.00')
            ->assertJsonPath('balance_due', '850.00')
            ->json();

        $this->assertDatabaseHas('sale_payments', [
            'document_id' => $sale['id'], 'payment_method' => 'gold_exchange', 'amount' => '200.00',
        ]);

        $this->getJson('/api/gold/summary?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('summary.net_weight', '10.000')
            ->assertJsonPath('inventory.0.purity', '22K');
    }

    public function test_gold_exchange_cannot_exceed_sale_total(): void
    {
        $user = User::factory()->create();
        $user->organization->update(['gold_store' => true]);
        $branch = $user->organization->branches()->firstOrFail();
        $contact = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        GoldRate::create([
            'organization_id' => $user->organization_id, 'branch_id' => $branch->id, 'metal' => 'gold',
            'purity' => '22K', 'rate_per_gram' => 100, 'effective_on' => '2026-10-07', 'created_by' => $user->id,
        ]);
        $product = Product::create(['organization_id' => $user->organization_id, 'name' => 'Item', 'unit' => 'pc', 'active' => true]);

        $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $contact->id, 'document_date' => '2026-10-07',
            'payment_type' => 'due', 'gold_exchange_weight' => 2, 'gold_exchange_purity' => '22K',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('gold_exchange_weight');
    }
}
