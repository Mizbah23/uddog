<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_reconciles_recorded_cash_without_counting_internal_transfer_as_store_income(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Customer', 'type' => 'customer']);
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Supplier', 'type' => 'supplier']);
        $sale = $this->document($staff, $branch, $customer, 'sale', 'SAL-CASH', '2026-10-01', 180);
        $sale->payments()->create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'amount' => 100, 'payment_method' => 'cash', 'kind' => 'initial', 'payment_date' => '2026-10-01', 'created_by' => $staff->id]);
        $sale->payments()->create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'amount' => 50, 'payment_method' => 'card', 'kind' => 'payment', 'payment_date' => '2026-10-01', 'created_by' => $staff->id]);
        $purchase = $this->document($staff, $branch, $supplier, 'purchase', 'PUR-CASH', '2026-10-02', 20);
        $purchase->purchasePayments()->create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'amount' => 20, 'payment_method' => 'cash', 'payment_date' => '2026-10-02', 'created_by' => $staff->id]);
        Expense::create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'category' => 'Other', 'description' => 'Transport', 'amount' => 10, 'expense_date' => '2026-10-03', 'payment_method' => 'cash', 'created_by' => $staff->id]);
        $this->document($staff, $branch, $customer, 'sale_return', 'SRT-CASH', '2026-10-04', 15, 'cash');
        $this->document($staff, $branch, $supplier, 'purchase_return', 'PRT-CASH', '2026-10-05', 7, 'cash');
        $transfer = CashMovement::create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'from_user_id' => $staff->id, 'to_user_id' => $owner->id, 'type' => 'transfer', 'amount' => 62, 'notes' => 'End of shift']);
        $transfer->forceFill(['created_at' => '2026-10-06 12:00:00'])->save();
        $withdrawal = CashMovement::create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'from_user_id' => $owner->id, 'type' => 'withdrawal', 'amount' => 25]);
        $withdrawal->forceFill(['created_at' => '2026-10-07 12:00:00'])->save();

        $report = $this->actingAs($owner)->getJson('/api/reports?report=cash_movement&branch_id='.$branch->id.'&date_from=2026-10-02&date_to=2026-10-07')
            ->assertOk()->assertJsonPath('summary.opening_balance', 100)
            ->assertJsonPath('summary.opening_sales_balance', 180)
            ->assertJsonPath('summary.cash_collected', 0)
            ->assertJsonPath('summary.sales_returns', 15)
            ->assertJsonPath('summary.staff_transfers', 62)
            ->assertJsonPath('summary.owner_withdrawals', 25)
            ->assertJsonPath('summary.closing_balance', 37)
            ->assertJsonPath('summary.closing_sales_balance', 140)
            ->assertJsonCount(6, 'rows');
        $transferRow = collect($report->json('rows'))->firstWhere('reference', 'CASH-'.$transfer->id);
        $this->assertSame(0, $transferRow['cash_in']);
        $this->assertSame(0, $transferRow['cash_out']);
        $this->assertSame(62, $transferRow['transfer']);
        $this->assertSame(62, $transferRow['store_balance']);
        $this->assertSame(165, $transferRow['sales_balance']);
        $this->assertSame('End of shift', $transferRow['notes']);

        $ledger = $this->getJson('/api/reports?report=general_ledger&branch_id='.$branch->id.'&account=cash&date_from=2026-10-01&date_to=2026-10-07')
            ->assertOk()->assertJsonPath('summary.closing_balance', 37);
        $transferEntries = collect($ledger->json('rows'))->where('reference', 'CASH-'.$transfer->id);
        $this->assertSame(2, $transferEntries->count());
        $this->assertSame(62, $transferEntries->sum('debit'));
        $this->assertSame(62, $transferEntries->sum('credit'));
        $this->assertSame('cash_movement', $transferEntries->first()['source_type']);
        $this->getJson('/api/reports?report=general_ledger&branch_id='.$branch->id.'&account=owner_drawings&date_from=2026-10-01&date_to=2026-10-07')
            ->assertOk()->assertJsonPath('summary.closing_balance', 25);
        $allAccounts = $this->getJson('/api/reports?report=general_ledger&branch_id='.$branch->id.'&date_from=2026-10-01&date_to=2026-10-07')->assertOk();
        $this->assertSame($allAccounts->json('summary.total_debit'), $allAccounts->json('summary.total_credit'));
    }

    public function test_cash_report_requires_setup_owner_role_and_correct_branch(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $staff = User::factory()->staff()->for($owner->organization)->create(['permissions' => array_map(fn (Permission $permission) => $permission->value, Permission::cases()), 'report_permissions' => ['cash_movement']]);
        $other = User::factory()->admin()->create();
        $other->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);

        $this->actingAs($owner)->getJson('/api/reports?report=cash_movement&branch_id='.$branch->id)->assertForbidden();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $this->actingAs($staff)->getJson('/api/reports?report=cash_movement&branch_id='.$branch->id)->assertForbidden();
        $this->actingAs($other)->getJson('/api/reports?report=cash_movement&branch_id='.$branch->id)->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->actingAs($owner)->getJson('/api/reports?report=cash_movement&branch_id='.$branch->id)->assertOk();
    }

    private function document(User $creator, Branch $branch, Contact $contact, string $type, string $number, string $date, int $total, ?string $paymentMethod = null): Document
    {
        return Document::create([
            'organization_id' => $creator->organization_id, 'branch_id' => $branch->id,
            'number' => $number, 'type' => $type, 'contact_id' => $contact->id,
            'document_date' => $date, 'subtotal' => $total, 'total' => $total,
            'payment_method' => $paymentMethod, 'created_by' => $creator->id,
        ]);
    }
}
