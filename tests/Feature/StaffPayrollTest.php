<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaffPayrollTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_creates_edits_and_removes_staff_profiles(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $payload = [
            'branch_id' => $branch->id, 'name' => 'Amina Akter', 'job_title' => 'Sales associate',
            'phone' => '01700000000', 'email' => null, 'monthly_salary' => 25000,
            'start_date' => '2026-09-01', 'active' => true,
        ];

        $created = $this->actingAs($owner)->postJson('/api/staff', $payload)->assertCreated()->assertJsonPath('name', 'Amina Akter');
        $id = $created->json('id');
        $this->putJson("/api/staff/{$id}", [...$payload, 'monthly_salary' => 27000])->assertOk()->assertJsonPath('monthly_salary', '27000.00');
        $this->getJson("/api/staff?branch_id={$branch->id}")->assertOk()->assertJsonCount(1);
        $this->deleteJson("/api/staff/{$id}")->assertOk();

        $this->getJson("/api/staff?branch_id={$branch->id}")->assertOk()->assertJsonCount(0);
        $this->assertSoftDeleted('staff_members', ['id' => $id]);
    }

    public function test_salary_batch_creates_one_linked_expense_per_eligible_staff_and_rejects_duplicate_month(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        foreach ([['Amina', 25000, '2026-09-01', true], ['Bina', 18000, '2026-10-01', true], ['Future hire', 30000, '2026-11-01', true], ['Inactive', 12000, '2026-09-01', false]] as [$name, $salary, $start, $active]) {
            StaffMember::factory()->create([
                'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
                'name' => $name, 'monthly_salary' => $salary, 'start_date' => $start, 'active' => $active,
            ]);
        }
        $payload = ['branch_id' => $branch->id, 'period' => '2026-10', 'payment_date' => '2026-10-31', 'payment_method' => 'bank_transfer'];

        $this->actingAs($owner)->postJson('/api/payroll', $payload)
            ->assertCreated()->assertJsonPath('staff_count', 2)->assertJsonPath('total_amount', '43000.00')->assertJsonCount(2, 'items');
        $this->postJson('/api/payroll', $payload)->assertUnprocessable()->assertJsonValidationErrors('period');

        $this->assertDatabaseCount('payroll_runs', 1);
        $this->assertDatabaseCount('payroll_items', 2);
        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseHas('expenses', ['category' => 'Payroll', 'description' => 'Salary for October 2026 — Amina', 'amount' => 25000, 'expense_date' => '2026-10-31']);
        $this->getJson("/api/expenses?branch_id={$branch->id}&date_from=2026-10-01&date_to=2026-10-31")
            ->assertOk()->assertJsonPath('summary.total', 43000);

        $amina = StaffMember::query()->where('name', 'Amina')->firstOrFail();
        $amina->update(['monthly_salary' => 30000]);
        $amina->delete();
        $this->getJson("/api/payroll?branch_id={$branch->id}")
            ->assertOk()->assertJsonPath('0.items.0.staff_name', 'Amina')->assertJsonPath('0.items.0.amount', '25000.00');
        $this->assertDatabaseHas('expenses', ['description' => 'Salary for October 2026 — Amina', 'amount' => 25000]);
    }

    public function test_payroll_expenses_cannot_be_edited_or_deleted_as_ordinary_expenses(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        StaffMember::factory()->create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id]);
        $this->actingAs($owner)->postJson('/api/payroll', ['branch_id' => $branch->id, 'period' => '2026-10', 'payment_date' => '2026-10-31', 'payment_method' => 'cash'])->assertCreated();
        $expense = Expense::query()->firstOrFail();

        $this->putJson("/api/expenses/{$expense->id}", [])->assertUnprocessable();
        $this->deleteJson("/api/expenses/{$expense->id}")->assertUnprocessable();

        $this->assertModelExists($expense);
        $this->assertDatabaseCount('payroll_items', 1);
    }

    public function test_rejects_a_month_without_eligible_staff_and_does_not_create_expenses(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        StaffMember::factory()->create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'start_date' => '2026-11-01',
        ]);

        $this->actingAs($owner)->postJson('/api/payroll', [
            'branch_id' => $branch->id, 'period' => '2026-10',
            'payment_date' => '2026-10-31', 'payment_method' => 'cash',
        ])->assertUnprocessable()->assertJsonValidationErrors('period');

        $this->assertDatabaseCount('payroll_runs', 0);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_other_company_and_non_admin_cannot_manage_staff_or_run_payroll(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $member = StaffMember::factory()->create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id]);
        $otherOwner = User::factory()->admin()->create();
        $otherBranch = $otherOwner->organization->branches()->firstOrFail();
        $staffUser = User::factory()->staff()->create(['organization_id' => $owner->organization_id]);

        $this->actingAs($otherOwner)->deleteJson("/api/staff/{$member->id}")->assertNotFound();
        $this->postJson('/api/payroll', ['branch_id' => $branch->id, 'period' => '2026-10', 'payment_date' => '2026-10-31', 'payment_method' => 'cash'])->assertUnprocessable();
        $this->getJson("/api/staff?branch_id={$otherBranch->id}")->assertOk()->assertJsonCount(0);

        $this->actingAs($staffUser)->getJson("/api/staff?branch_id={$branch->id}")->assertForbidden();
        $this->postJson('/api/payroll', ['branch_id' => $branch->id, 'period' => '2026-10', 'payment_date' => '2026-10-31', 'payment_method' => 'cash'])->assertForbidden();
        $this->assertDatabaseCount('payroll_runs', 0);
        $this->assertModelExists($member);
    }
}
