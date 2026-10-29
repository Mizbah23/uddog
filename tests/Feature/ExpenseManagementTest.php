<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_filters_and_totals_cover_all_matching_records_not_just_the_current_page(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();

        for ($index = 0; $index < 27; $index++) {
            Expense::create([
                'organization_id' => $owner->organization_id,
                'branch_id' => $branch->id,
                'category' => 'Rent',
                'description' => "October rent {$index}",
                'amount' => 10,
                'expense_date' => '2026-10-13',
                'payment_method' => 'cash',
                'created_by' => $owner->id,
            ]);
        }
        Expense::create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branch->id,
            'category' => 'Utilities',
            'description' => 'September electricity',
            'amount' => 50,
            'expense_date' => '2026-09-30',
            'payment_method' => 'card',
            'created_by' => $owner->id,
        ]);

        $url = "/api/expenses?branch_id={$branch->id}&date_from=2026-10-01&date_to=2026-10-31&category=Rent";
        $this->actingAs($owner)->getJson($url)
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('summary.count', 27)
            ->assertJsonPath('summary.total', 270)
            ->assertJsonPath('pagination.last_page', 2);

        $this->getJson("{$url}&page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.count', 27);

        $this->getJson("/api/expenses?branch_id={$branch->id}&search=electricity&payment_method=card")
            ->assertOk()
            ->assertJsonPath('summary.count', 1)
            ->assertJsonPath('summary.total', 50);
    }

    public function test_expenses_can_be_created_updated_and_removed_but_not_across_companies(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $payload = [
            'branch_id' => $branch->id,
            'category' => 'Insurance',
            'description' => 'Annual policy',
            'amount' => 1200.50,
            'expense_date' => '2026-10-13',
            'payment_method' => 'bank_transfer',
            'notes' => 'Receipt A1',
        ];

        $response = $this->actingAs($owner)->postJson('/api/expenses', $payload)
            ->assertCreated()
            ->assertJsonPath('category', 'Insurance');
        $id = $response->json('id');

        $this->putJson("/api/expenses/{$id}", [...$payload, 'amount' => 1250])->assertOk()->assertJsonPath('amount', '1250.00');
        $this->getJson("/api/expenses?branch_id={$branch->id}")->assertJsonPath('summary.total', 1250);

        $otherOwner = User::factory()->admin()->create();
        $this->actingAs($otherOwner)->putJson("/api/expenses/{$id}", [...$payload, 'amount' => 1])->assertNotFound();
        $this->deleteJson("/api/expenses/{$id}")->assertNotFound();

        $this->actingAs($owner)->deleteJson("/api/expenses/{$id}")->assertOk();
        $this->assertDatabaseMissing('expenses', ['id' => $id]);
    }

    public function test_managers_and_staff_only_access_expenses_they_recorded(): void
    {
        $owner = User::factory()->admin()->create();
        $manager = User::factory()->for($owner->organization)->create();
        $staff = User::factory()->staff()->for($owner->organization)->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $expenses = collect([$owner, $manager, $staff])->mapWithKeys(function (User $user) use ($branch): array {
            $expense = Expense::create([
                'organization_id' => $user->organization_id,
                'branch_id' => $branch->id,
                'category' => 'Other',
                'description' => "Expense by {$user->name}",
                'amount' => 100,
                'expense_date' => '2026-10-13',
                'payment_method' => 'cash',
                'created_by' => $user->id,
            ]);

            return [$user->id => $expense->id];
        });

        foreach ([$manager, $staff] as $user) {
            $this->actingAs($user)->getJson("/api/expenses?branch_id={$branch->id}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $expenses[$user->id])
                ->assertJsonPath('summary.count', 1)
                ->assertJsonPath('summary.total', 100);

            $otherExpenseId = $expenses[$owner->id];
            $this->putJson("/api/expenses/{$otherExpenseId}", [])->assertNotFound();
            $this->deleteJson("/api/expenses/{$otherExpenseId}")->assertNotFound();
        }

        $this->actingAs($owner)->getJson("/api/expenses?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('summary.count', 3)
            ->assertJsonPath('summary.total', 300);
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner)->getJson('/api/expenses?date_from=2026-10-14&date_to=2026-10-13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_custom_expense_categories_remain_available_after_deleting_an_expense_and_are_company_scoped(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $expense = $this->actingAs($owner)->postJson('/api/expenses', [
            'branch_id' => $branch->id,
            'category' => 'Office supplies',
            'description' => 'Printer paper',
            'amount' => 100,
            'expense_date' => '2026-10-13',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->deleteJson('/api/expenses/'.$expense->json('id'))->assertOk();
        $this->getJson('/api/expenses?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('summary.count', 0)
            ->assertJsonPath('categories.7', 'Office supplies');

        $otherOwner = User::factory()->admin()->create();
        $otherBranch = $otherOwner->organization->branches()->firstOrFail();
        $this->actingAs($otherOwner)->getJson('/api/expenses?branch_id='.$otherBranch->id)
            ->assertOk()
            ->assertDontSee('Office supplies');
    }

    public function test_profit_and_loss_deducts_expenses_in_the_selected_period(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        foreach (['2026-10-13', '2026-09-30'] as $date) {
            Expense::create([
                'organization_id' => $owner->organization_id,
                'branch_id' => $branch->id,
                'category' => 'Utilities',
                'description' => 'Electricity',
                'amount' => 100,
                'expense_date' => $date,
                'payment_method' => 'cash',
                'created_by' => $owner->id,
            ]);
        }

        $this->actingAs($owner)->getJson("/api/reports?report=profit_loss&branch_id={$branch->id}&date_from=2026-10-01&date_to=2026-10-31")
            ->assertOk()
            ->assertJsonPath('summary.operating_expenses', 100)
            ->assertJsonPath('summary.net_profit', -100);
    }
}
