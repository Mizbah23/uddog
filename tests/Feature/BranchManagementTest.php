<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_starts_with_main_branch_and_can_create_more_branches(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->getJson('/api/branches')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Main Branch')
            ->assertJsonPath('0.is_default', true);

        $this->postJson('/api/branches', [
            'name' => 'Uttara Store',
            'code' => 'uttara',
            'phone' => '01700000000',
            'address' => 'Uttara, Dhaka',
            'is_default' => false,
            'active' => true,
        ])->assertCreated()
            ->assertJsonPath('code', 'UTTARA')
            ->assertJsonPath('documents_count', 0);

        $this->assertDatabaseCount('branches', 2);
    }

    public function test_stock_and_transactions_are_isolated_by_branch(): void
    {
        $user = User::factory()->create();
        $main = Branch::where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $second = Branch::create([
            'organization_id' => $user->organization_id,
            'name' => 'Second Store',
            'code' => 'SECOND',
            'active' => true,
        ]);
        $supplier = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Supplier', 'type' => 'supplier']);
        $customer = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        $product = Product::create(['organization_id' => $user->organization_id, 'name' => 'Branch item', 'unit' => 'pc', 'reorder_level' => 0]);
        $this->actingAs($user);

        $purchase = [
            'type' => 'purchase', 'branch_id' => $main->id, 'contact_id' => $supplier->id, 'document_date' => '2026-09-27',
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10]],
        ];
        $this->postJson('/api/documents', $purchase)->assertCreated()->assertJsonPath('branch_id', $main->id);

        $sale = [
            'type' => 'sale', 'branch_id' => $second->id, 'contact_id' => $customer->id, 'document_date' => '2026-09-27',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 15]],
        ];
        $this->postJson('/api/documents', $sale)->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->getJson('/api/products?branch_id='.$main->id)->assertOk()->assertJsonPath('0.quantity_on_hand', '5.000');
        $this->getJson('/api/products?branch_id='.$second->id)->assertOk()->assertJsonPath('0.quantity_on_hand', '0.000');
        $this->getJson('/api/documents?branch_id='.$main->id)->assertOk()->assertJsonCount(1);
        $this->getJson('/api/documents?branch_id='.$second->id)->assertOk()->assertJsonCount(0);
    }

    public function test_branch_from_another_company_cannot_be_selected(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $foreign = Branch::where('organization_id', $second->organization_id)->firstOrFail();

        $this->actingAs($first)->getJson('/api/products?branch_id='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }
}
