<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_expense_report_preserves_full_totals_dates_and_creator_names(): void
    {
        $owner = User::factory()->admin()->create(['name' => 'Full Owner Name']);
        $branch = $owner->organization->branches()->firstOrFail();
        $values = ['organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'category' => 'Rent', 'description' => 'Monthly rent', 'payment_method' => 'cash',
            'amount' => '10.25', 'created_by' => $owner->id, 'expense_date' => '2026-01-01'];
        for ($index = 0; $index < 125; $index++) {
            Expense::create($values);
        }
        Expense::create([...$values, 'expense_date' => '2025-12-31', 'amount' => '999.00']);
        $otherOwner = User::factory()->admin()->create();
        Expense::create([...$values, 'organization_id' => $otherOwner->organization_id,
            'branch_id' => $otherOwner->organization->branches()->firstOrFail()->id]);

        $this->actingAs($owner)->getJson('/api/reports?report=expenses&branch_id='.$branch->id.'&date_from=2026-01-01&date_to=2026-12-31')
            ->assertOk()->assertJsonCount(125, 'rows')
            ->assertJsonPath('summary.records', 125)
            ->assertJsonPath('summary.total_expenses', 1281.25)
            ->assertJsonPath('rows.0.date', '2026-01-01')
            ->assertJsonPath('rows.0.recorded_by', 'Full Owner Name');
    }
}
