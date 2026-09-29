<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_only_list_and_operate_in_assigned_branches(): void
    {
        $owner = User::factory()->admin()->create();
        $main = Branch::query()->where('organization_id', $owner->organization_id)->where('is_default', true)->firstOrFail();
        $uttara = Branch::create([
            'organization_id' => $owner->organization_id,
            'name' => 'Uttara Store',
            'code' => 'UTTARA',
            'is_default' => false,
            'active' => true,
        ]);
        $manager = User::factory()->for($owner->organization)->create([
            'permissions' => [Permission::Dashboard->value, Permission::Branches->value, Permission::StockTransfers->value],
        ]);
        $manager->accessibleBranches()->sync([$uttara->id]);

        $this->actingAs($manager)->getJson('/api/branches')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $uttara->id);

        $this->actingAs($manager)->getJson('/api/overview?branch_id='.$main->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->actingAs($manager)->getJson('/api/overview?branch_id='.$uttara->id)
            ->assertOk();

        $this->actingAs($manager)->putJson('/api/branches/'.$main->id, [
            'name' => $main->name,
            'code' => $main->code,
            'is_default' => true,
            'active' => true,
        ])->assertNotFound();

        $this->actingAs($manager)->postJson('/api/stock-transfers', [
            'from_branch_id' => $uttara->id,
            'to_branch_id' => $main->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_owner_assigns_one_or_many_branches_when_creating_and_editing_staff(): void
    {
        $owner = User::factory()->admin()->create();
        $main = Branch::query()->where('organization_id', $owner->organization_id)->where('is_default', true)->firstOrFail();
        $uttara = Branch::create([
            'organization_id' => $owner->organization_id,
            'name' => 'Uttara Store',
            'code' => 'UTTARA',
            'is_default' => false,
            'active' => true,
        ]);

        $created = $this->actingAs($owner)->postJson('/api/users', [
            'name' => 'Uttara manager',
            'email' => 'uttara.manager@example.test',
            'password' => 'password123',
            'role' => 'manager',
            'active' => true,
            'permissions' => [Permission::Dashboard->value],
            'branch_ids' => [$main->id, $uttara->id],
        ])->assertCreated()
            ->assertJsonCount(2, 'accessible_branches')
            ->json();

        $manager = User::findOrFail($created['id']);

        $this->actingAs($owner)->putJson('/api/users/'.$manager->id, [
            'name' => $manager->name,
            'email' => $manager->email,
            'role' => 'manager',
            'active' => true,
            'permissions' => [Permission::Dashboard->value],
            'branch_ids' => [$uttara->id],
        ])->assertOk()
            ->assertJsonCount(1, 'accessible_branches')
            ->assertJsonPath('accessible_branches.0.id', $uttara->id);

        $this->actingAs($manager->fresh())->getJson('/api/branches')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $uttara->id);
    }

    public function test_manager_and_staff_must_have_an_assigned_branch(): void
    {
        $owner = User::factory()->admin()->create();

        $this->actingAs($owner)->postJson('/api/users', [
            'name' => 'Unassigned manager',
            'email' => 'unassigned.manager@example.test',
            'password' => 'password123',
            'role' => 'manager',
            'active' => true,
            'permissions' => [Permission::Dashboard->value],
            'branch_ids' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('branch_ids');
    }
}
