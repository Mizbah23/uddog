<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\GoldJob;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\DefaultCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductContactDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_deletes_unused_product_customer_and_supplier(): void
    {
        $owner = User::factory()->admin()->create();
        $product = $this->product($owner);
        $customer = $this->contact($owner, 'customer');
        $supplier = $this->contact($owner, 'supplier');

        $this->actingAs($owner)->deleteJson("/api/products/{$product->id}")->assertOk()->assertJsonPath('ok', true);
        $this->deleteJson("/api/contacts/{$customer->id}")->assertOk()->assertJsonPath('ok', true);
        $this->deleteJson("/api/contacts/{$supplier->id}")->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_stocks', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $customer->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $supplier->id]);
    }

    public function test_default_customer_cannot_be_deleted_even_after_renaming_and_is_not_duplicated(): void
    {
        $owner = User::factory()->admin()->create();
        $defaultCustomer = Contact::create([
            'organization_id' => $owner->organization_id,
            'name' => DefaultCustomer::NAME,
            'type' => 'customer',
        ]);
        app(DefaultCustomer::class)->ensure(DB::connection(config('tenancy.central_connection')), $owner->organization);

        $this->assertTrue($defaultCustomer->fresh()->is_default);

        $this->actingAs($owner)->putJson("/api/contacts/{$defaultCustomer->id}", [
            'name' => 'Renamed walk-in customer',
            'type' => 'customer',
            'is_default' => false,
        ])->assertOk();

        $this->assertTrue($defaultCustomer->fresh()->is_default);

        app(DefaultCustomer::class)->ensure(DB::connection(config('tenancy.central_connection')), $owner->organization);
        $this->assertSame(1, Contact::query()->where('organization_id', $owner->organization_id)->count());

        $this->deleteJson("/api/contacts/{$defaultCustomer->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.contact.0', 'The default customer cannot be deleted.');
        $this->assertDatabaseHas('contacts', ['id' => $defaultCustomer->id, 'is_default' => true]);
    }

    public function test_staff_cannot_delete_catalog_records(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create(['permissions' => ['products', 'contacts']]);
        $product = $this->product($owner);
        $customer = $this->contact($owner, 'customer');

        $this->actingAs($staff)->deleteJson("/api/products/{$product->id}")->assertForbidden();
        $this->deleteJson("/api/contacts/{$customer->id}")->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('contacts', ['id' => $customer->id]);
    }

    public function test_product_with_transaction_history_cannot_be_deleted_even_when_stock_is_zero(): void
    {
        $owner = User::factory()->admin()->create();
        $supplier = $this->contact($owner, 'supplier');
        $customer = $this->contact($owner, 'customer');
        $product = $this->product($owner);
        $this->actingAs($owner);

        $this->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => today()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10]],
        ])->assertCreated();
        $this->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => today()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 15]],
        ])->assertCreated();

        $this->assertSame('0.000', $product->fresh()->quantity_on_hand);
        $this->deleteJson("/api/products/{$product->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.product.0', 'This product has stock or transaction history and cannot be deleted.');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_product_with_other_branch_stock_or_gold_job_cannot_be_deleted(): void
    {
        $owner = User::factory()->admin()->create();
        $product = $this->product($owner);
        $mainBranch = Branch::query()->where('organization_id', $owner->organization_id)->firstOrFail();
        $otherBranch = Branch::create([
            'organization_id' => $owner->organization_id, 'name' => 'Second branch', 'code' => 'SECOND',
            'is_default' => false, 'active' => true,
        ]);
        ProductStock::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $otherBranch->id,
            'product_id' => $product->id, 'quantity_on_hand' => 3,
        ]);

        $this->actingAs($owner)->deleteJson("/api/products/{$product->id}")->assertUnprocessable();

        ProductStock::query()->where('product_id', $product->id)->where('branch_id', $otherBranch->id)->update(['quantity_on_hand' => 0]);
        GoldJob::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $mainBranch->id, 'number' => 'JOB-DELETE-1',
            'job_type' => 'repair', 'product_id' => $product->id, 'created_by' => $owner->id,
        ]);

        $this->deleteJson("/api/products/{$product->id}")->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_contacts_with_documents_opening_balances_or_gold_jobs_cannot_be_deleted(): void
    {
        $owner = User::factory()->admin()->create();
        $supplier = $this->contact($owner, 'supplier');
        $customer = $this->contact($owner, 'customer');
        $product = $this->product($owner);
        $branch = Branch::query()->where('organization_id', $owner->organization_id)->firstOrFail();
        $this->actingAs($owner)->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => today()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertCreated();

        $this->deleteJson("/api/contacts/{$supplier->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.contact.0', 'This customer or supplier has transactions or an opening balance and cannot be deleted.');

        $customer->update(['opening_due' => 25]);
        $this->deleteJson("/api/contacts/{$customer->id}")->assertUnprocessable()->assertJsonValidationErrors('contact');
        $customer->update(['opening_due' => 0]);
        GoldJob::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'number' => 'JOB-DELETE-2',
            'job_type' => 'repair', 'contact_id' => $customer->id, 'created_by' => $owner->id,
        ]);
        $this->deleteJson("/api/contacts/{$customer->id}")->assertUnprocessable()->assertJsonValidationErrors('contact');

        $this->assertDatabaseHas('contacts', ['id' => $supplier->id]);
        $this->assertDatabaseHas('contacts', ['id' => $customer->id]);
    }

    public function test_owner_cannot_delete_another_companys_records(): void
    {
        $owner = User::factory()->admin()->create();
        $otherOwner = User::factory()->admin()->create();
        $product = $this->product($otherOwner);
        $supplier = $this->contact($otherOwner, 'supplier');

        $this->actingAs($owner)->deleteJson("/api/products/{$product->id}")->assertNotFound();
        $this->deleteJson("/api/contacts/{$supplier->id}")->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('contacts', ['id' => $supplier->id]);
    }

    public function test_superadmin_can_delete_unused_records_in_company_support_mode(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->admin()->for($organization)->create();
        $superadmin = User::factory()->superadmin()->create();
        $product = $this->product($owner);
        $customer = $this->contact($owner, 'customer');

        $this->actingAs($superadmin)->postJson("/api/clients/{$organization->id}/impersonate")->assertOk();
        $this->deleteJson("/api/products/{$product->id}")->assertOk();
        $this->deleteJson("/api/contacts/{$customer->id}")->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $customer->id]);
    }

    public function test_superadmin_cannot_delete_the_default_customer_in_support_mode(): void
    {
        $organization = Organization::factory()->create();
        User::factory()->admin()->for($organization)->create();
        $superadmin = User::factory()->superadmin()->create();
        app(DefaultCustomer::class)->ensure(DB::connection(config('tenancy.central_connection')), $organization);
        $defaultCustomer = Contact::query()->where('organization_id', $organization->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($superadmin)->postJson("/api/clients/{$organization->id}/impersonate")->assertOk();
        $this->deleteJson("/api/contacts/{$defaultCustomer->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.contact.0', 'The default customer cannot be deleted.');
        $this->assertDatabaseHas('contacts', ['id' => $defaultCustomer->id]);
    }

    private function product(User $owner): Product
    {
        return Product::create([
            'organization_id' => $owner->organization_id, 'name' => 'Unused product', 'unit' => 'pc',
            'quantity_on_hand' => 0, 'cost_price' => 10, 'sale_price' => 15, 'reorder_level' => 0,
        ]);
    }

    private function contact(User $owner, string $type): Contact
    {
        return Contact::create(['organization_id' => $owner->organization_id, 'name' => ucfirst($type), 'type' => $type]);
    }
}
