<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_and_supplier_directories_show_profile_and_financial_totals(): void
    {
        $user = User::factory()->admin()->create();
        $supplier = Contact::create([
            'organization_id' => $user->organization_id, 'name' => 'North Supplier', 'company' => 'North Trading',
            'type' => 'supplier', 'phone' => '01710000000', 'address' => 'Uttara, Dhaka', 'active' => true,
        ]);
        $customer = Contact::create([
            'organization_id' => $user->organization_id, 'name' => 'Retail Customer', 'company' => 'Retail Co.',
            'type' => 'customer', 'phone' => '01720000000', 'address' => 'Dhanmondi, Dhaka', 'area' => 'Dhanmondi',
            'category' => 'Retail', 'credit_limit' => 5000, 'active' => true,
        ]);
        $product = Product::create([
            'organization_id' => $user->organization_id, 'name' => 'Directory item', 'unit' => 'pc',
            'quantity_on_hand' => 0, 'reorder_level' => 0, 'active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'purchase', 'contact_id' => $supplier->id, 'document_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated();
        $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'sale_channel' => 'pos', 'contact_id' => $customer->id, 'document_date' => now()->toDateString(),
            'payment_method' => 'cash', 'amount_paid' => 60,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50]],
        ])->assertCreated();

        $this->actingAs($user)->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Retail Customer')
            ->assertJsonPath('0.area', 'Dhanmondi')
            ->assertJsonPath('0.category', 'Retail')
            ->assertJsonPath('0.credit_limit', '5000.00')
            ->assertJsonPath('0.sales_total', '100.00')
            ->assertJsonPath('0.paid_total', '60.00')
            ->assertJsonPath('0.balance_total', '40.00');

        $this->actingAs($user)->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.company', 'North Trading')
            ->assertJsonPath('0.purchases_total', '100.00')
            ->assertJsonPath('0.paid_total', '0.00')
            ->assertJsonPath('0.balance_total', '100.00');
    }

    public function test_contact_directory_is_scoped_to_the_company_and_requires_contacts_permission(): void
    {
        $user = User::factory()->admin()->create();
        $foreignUser = User::factory()->admin()->create();
        Contact::create(['organization_id' => $foreignUser->organization_id, 'name' => 'Foreign customer', 'type' => 'customer']);

        $this->actingAs($user)->getJson('/api/customers')->assertOk()->assertJsonCount(0);

        $staff = User::factory()->staff()->for($user->organization)->create(['permissions' => []]);
        $this->actingAs($staff)->getJson('/api/customers')->assertForbidden();

        $staff->update(['permissions' => [Permission::Contacts->value]]);
        $this->actingAs($staff->fresh())->getJson('/api/customers')->assertOk();
        $this->actingAs($staff->fresh())->getJson('/api/suppliers')->assertOk();
    }

    public function test_contact_profile_fields_can_be_saved(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/contacts', [
            'name' => 'New customer', 'type' => 'customer', 'company' => 'Customer Company', 'phone' => '01900000000',
            'area' => 'Mirpur', 'category' => 'Wholesale', 'credit_limit' => 12000, 'active' => false,
        ])->assertCreated()
            ->assertJsonPath('company', 'Customer Company')
            ->assertJsonPath('credit_limit', '12000.00')
            ->assertJsonPath('active', false);
    }
}
