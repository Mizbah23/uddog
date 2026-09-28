<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_target_reports_net_sales_after_sales_returns(): void
    {
        $user = User::factory()->admin()->create();
        $branch = Branch::query()->where('organization_id', $user->organization_id)->where('is_default', true)->firstOrFail();
        $customer = Contact::create(['organization_id' => $user->organization_id, 'name' => 'Target customer', 'type' => 'customer']);
        $product = Product::create([
            'organization_id' => $user->organization_id, 'name' => 'Target item', 'unit' => 'pc',
            'quantity_on_hand' => 5, 'reorder_level' => 0, 'active' => true,
        ]);
        $period = now()->format('Y-m');
        $sale = $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json();
        $this->actingAs($user)->postJson('/api/documents', [
            'type' => 'sale_return', 'contact_id' => $customer->id, 'sale_id' => $sale['id'], 'document_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/sales-targets', [
            'branch_id' => $branch->id, 'period' => $period, 'target_amount' => 80,
        ])->assertCreated();

        $this->actingAs($user)->getJson('/api/sales-targets?period='.$period)
            ->assertOk()
            ->assertJsonPath('targets.0.branch.id', $branch->id)
            ->assertJsonPath('targets.0.actual_amount', '0.00')
            ->assertJsonPath('targets.0.remaining_amount', '80.00');
    }

    public function test_sales_target_can_be_assigned_to_an_employee_and_is_scoped_to_the_company(): void
    {
        $user = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($user->organization)->create();
        $branch = Branch::query()->where('organization_id', $user->organization_id)->firstOrFail();
        $period = now()->format('Y-m');

        $target = $this->actingAs($user)->postJson('/api/sales-targets', [
            'branch_id' => $branch->id, 'user_id' => $staff->id, 'period' => $period,
            'target_amount' => 500, 'notes' => 'Campaign goal',
        ])->assertCreated()->assertJsonPath('user.id', $staff->id)->json();

        $this->actingAs($user)->putJson('/api/sales-targets/'.$target['id'], [
            'branch_id' => $branch->id, 'user_id' => $staff->id, 'period' => $period,
            'target_amount' => 650, 'notes' => 'Updated campaign goal',
        ])->assertOk()->assertJsonPath('target_amount', '650.00');

        $foreignUser = User::factory()->admin()->create();
        $this->actingAs($foreignUser)->getJson('/api/sales-targets?period='.$period)
            ->assertOk()->assertJsonCount(0, 'targets');
        $this->actingAs($foreignUser)->deleteJson('/api/sales-targets/'.$target['id'])->assertNotFound();
    }

    public function test_sales_targets_require_the_assigned_permission_for_staff(): void
    {
        $staff = User::factory()->staff()->create(['permissions' => []]);

        $this->actingAs($staff)->getJson('/api/sales-targets')->assertForbidden();

        $staff->update(['permissions' => [Permission::SalesTargets->value]]);
        $this->actingAs($staff->fresh())->getJson('/api/sales-targets')->assertOk()->assertJsonStructure(['targets', 'users']);
    }
}
