<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Permission;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_only_use_permissions_assigned_by_the_owner(): void
    {
        $staff = User::factory()->staff()->create(['permissions' => [Permission::Sales->value]]);
        $customer = Contact::create(['organization_id' => $staff->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        $product = Product::create([
            'organization_id' => $staff->organization_id,
            'name' => 'Stocked product',
            'unit' => 'pc',
            'cost_price' => 5,
            'sale_price' => 10,
            'quantity_on_hand' => 5,
            'reorder_level' => 1,
        ]);

        $this->actingAs($staff)->getJson('/api/overview')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/products')->assertOk();
        $this->actingAs($staff)->postJson('/api/products', [])->assertForbidden();
        $this->actingAs($staff)->postJson("/api/products/{$product->id}/adjust", [
            'quantity_change' => 1,
            'notes' => 'Not permitted',
        ])->assertForbidden();

        $this->actingAs($staff)->postJson('/api/documents', [
            'type' => 'sale',
            'contact_id' => $customer->id,
            'document_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertCreated();

        $this->actingAs($staff)->postJson('/api/documents', [
            'type' => 'purchase',
            'contact_id' => $customer->id,
            'document_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 5]],
        ])->assertForbidden();
    }

    public function test_owner_can_change_staff_permissions_but_cannot_create_another_owner(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($admin->organization)->create(['permissions' => []]);

        $this->actingAs($admin)->putJson("/api/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'password' => '',
            'role' => UserRole::Staff->value,
            'organization_id' => $admin->organization_id,
            'active' => true,
            'permissions' => [Permission::Dashboard->value, Permission::Inventory->value],
        ])->assertOk()->assertJsonPath('permissions.0', Permission::Dashboard->value);

        $this->actingAs($staff->fresh())->getJson('/api/overview')->assertOk();

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Another Owner',
            'email' => 'another-owner@example.test',
            'password' => 'password',
            'role' => UserRole::Admin->value,
            'active' => true,
            'permissions' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }
}
