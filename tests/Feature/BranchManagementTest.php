<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\StockCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_starts_with_main_branch_but_cannot_create_or_delete_branches(): void
    {
        $user = User::factory()->admin()->create();
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
        ])->assertStatus(405);

        $main = Branch::where('organization_id', $user->organization_id)->firstOrFail();
        $this->deleteJson("/api/branches/{$main->id}")->assertStatus(405);

        $this->assertDatabaseCount('branches', 1);
    }

    public function test_superadmin_can_add_and_delete_an_empty_company_branch(): void
    {
        $companyOwner = User::factory()->admin()->create();
        $superadmin = User::factory()->superadmin()->create();

        $created = $this->actingAs($superadmin)->postJson("/api/clients/{$companyOwner->organization_id}/branches", [
            'name' => 'Uttara Store',
            'code' => 'uttara',
            'phone' => '01700000000',
            'address' => 'Uttara, Dhaka',
            'is_default' => false,
            'active' => true,
        ])->assertCreated()
            ->assertJsonPath('code', 'UTTARA')
            ->assertJsonPath('documents_count', 0);

        $branchId = $created->json('id');
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'organization_id' => $companyOwner->organization_id]);

        $this->deleteJson("/api/clients/{$companyOwner->organization_id}/branches/{$branchId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('branches', ['id' => $branchId]);
    }

    public function test_superadmin_can_see_company_branches_in_company_details(): void
    {
        $owner = User::factory()->admin()->create();
        $main = Branch::where('organization_id', $owner->organization_id)->firstOrFail();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->getJson("/api/clients/{$owner->organization_id}")
            ->assertOk()
            ->assertJsonPath('branches.0.id', $main->id)
            ->assertJsonPath('branches.0.documents_count', 0);
    }

    public function test_company_owner_can_edit_an_existing_branch(): void
    {
        $user = User::factory()->admin()->create();
        $main = Branch::where('organization_id', $user->organization_id)->firstOrFail();

        $this->actingAs($user)->putJson("/api/branches/{$main->id}", [
            'name' => 'Head Office',
            'code' => 'MAIN',
            'phone' => '01700000000',
            'is_default' => true,
            'active' => true,
        ])->assertOk()->assertJsonPath('name', 'Head Office');

        $this->assertDatabaseHas('branches', ['id' => $main->id, 'name' => 'Head Office']);
    }

    public function test_company_owner_cannot_use_superadmin_branch_endpoints(): void
    {
        $user = User::factory()->admin()->create();
        $main = Branch::where('organization_id', $user->organization_id)->firstOrFail();

        $this->actingAs($user)->postJson("/api/clients/{$user->organization_id}/branches", [
            'name' => 'Uttara Store', 'code' => 'UTTARA', 'is_default' => false, 'active' => true,
        ])->assertForbidden();

        $this->deleteJson("/api/clients/{$user->organization_id}/branches/{$main->id}")->assertForbidden();

        $this->assertDatabaseCount('branches', 1);
    }

    public function test_superadmin_cannot_delete_a_branch_from_another_company(): void
    {
        $first = User::factory()->admin()->create();
        $second = User::factory()->admin()->create();
        $foreign = Branch::where('organization_id', $second->organization_id)->firstOrFail();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->deleteJson("/api/clients/{$first->organization_id}/branches/{$foreign->id}")
            ->assertNotFound();

        $this->assertModelExists($foreign);
    }

    public function test_superadmin_cannot_delete_a_default_branch(): void
    {
        $owner = User::factory()->admin()->create();
        $main = Branch::where('organization_id', $owner->organization_id)->firstOrFail();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->deleteJson("/api/clients/{$owner->organization_id}/branches/{$main->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch');

        $this->assertModelExists($main);
    }

    public function test_superadmin_cannot_delete_a_branch_with_recorded_activity(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = Branch::create([
            'organization_id' => $owner->organization_id,
            'name' => 'Uttara Store',
            'code' => 'UTTARA',
            'active' => true,
        ]);
        StockCheck::create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branch->id,
            'number' => 'CHECK-001',
            'check_date' => '2026-10-08',
            'created_by' => $owner->id,
        ]);
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)->deleteJson("/api/clients/{$owner->organization_id}/branches/{$branch->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch');

        $this->assertModelExists($branch);
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
        $user->accessibleBranches()->sync([$main->id, $second->id]);
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
