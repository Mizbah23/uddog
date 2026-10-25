<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PurchasePayment;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function users(): array
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->staff()->for($owner->organization)->create();
        $branch = $owner->organization->branches()->firstOrFail();

        return [$owner, $staff, $branch];
    }

    private function payment(User $staff, int $branchId, string $amount, string $method = 'cash', string $type = 'sale'): void
    {
        $contact = Contact::create(['organization_id' => $staff->organization_id, 'name' => 'Cash contact', 'type' => 'both']);
        $document = Document::create([
            'organization_id' => $staff->organization_id, 'branch_id' => $branchId,
            'number' => 'CASH-'.uniqid(), 'type' => $type, 'contact_id' => $contact->id,
            'document_date' => today()->toDateString(), 'subtotal' => $amount,
            'total' => $amount, 'created_by' => $staff->id,
        ]);
        $fields = [
            'organization_id' => $staff->organization_id, 'branch_id' => $branchId,
            'document_id' => $document->id, 'amount' => $amount,
            'payment_method' => $method, 'payment_date' => today()->toDateString(), 'created_by' => $staff->id,
        ];
        if ($type === 'purchase') {
            PurchasePayment::create($fields);
        } else {
            SalePayment::create([...$fields, 'kind' => 'initial']);
        }
    }

    public function test_setting_is_off_by_default_and_blocks_cash_actions(): void
    {
        [$owner, $staff, $branch] = $this->users();
        $this->payment($staff, $branch->id, '100.00');

        $this->actingAs($staff)->getJson('/api/overview?branch_id='.$branch->id)->assertOk()->assertJsonPath('cash', null);
        $this->postJson('/api/cash/transfer', ['branch_id' => $branch->id])->assertForbidden();
        $this->actingAs($owner)->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 10])->assertForbidden();
    }

    public function test_full_handover_moves_only_recorded_physical_cash_and_owner_can_withdraw(): void
    {
        [$owner, $staff, $branch] = $this->users();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $this->payment($staff, $branch->id, '100.00');
        $this->payment($staff, $branch->id, '50.00', 'card');
        $this->payment($staff, $branch->id, '20.00', 'cash', 'purchase');
        Expense::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'category' => 'Other', 'description' => 'Cash expense', 'amount' => '10.00',
            'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'created_by' => $staff->id,
        ]);

        $this->actingAs($staff)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('cash.on_hand', '70.00')
            ->assertJsonPath('cash.cash_received', '100.00')
            ->assertJsonPath('cash.cash_paid', '30.00');
        $this->postJson('/api/cash/transfer', ['branch_id' => $branch->id, 'notes' => 'End of shift'])
            ->assertCreated()->assertJsonPath('movement.amount', '70.00')->assertJsonPath('cash.on_hand', '0.00');
        $this->postJson('/api/cash/transfer', ['branch_id' => $branch->id])->assertUnprocessable();
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 1])->assertForbidden();

        $this->actingAs($owner)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('cash.on_hand', '70.00')
            ->assertJsonPath('cash.received_transfers', '70.00')
            ->assertJsonPath('cash.staff_balances.0.on_hand', '0.00');
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 71])->assertUnprocessable();
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 25, 'notes' => 'Bank deposit'])
            ->assertCreated()->assertJsonPath('cash.on_hand', '45.00')
            ->assertJsonPath('cash.withdrawn', '25.00');
    }

    public function test_cash_actions_are_scoped_to_the_company_and_accessible_branch(): void
    {
        [$owner, $staff, $branch] = $this->users();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $this->payment($staff, $branch->id, '20.00');
        $other = User::factory()->admin()->create();
        $otherBranch = $other->organization->branches()->firstOrFail();

        $this->actingAs($staff)->postJson('/api/cash/transfer', ['branch_id' => $otherBranch->id])->assertUnprocessable();
        $this->actingAs($other)->getJson('/api/overview?branch_id='.$otherBranch->id)->assertOk()->assertJsonPath('cash', null);
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 1])->assertForbidden();
    }

    public function test_cash_returns_adjust_the_handler_balance_but_credit_returns_do_not(): void
    {
        [$owner, $staff, $branch] = $this->users();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $this->payment($staff, $branch->id, '100.00');
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Return contact', 'type' => 'both']);
        foreach ([['sale_return', 'cash', '15.00'], ['purchase_return', 'cash', '7.00'], ['sale_return', 'credit', '12.00']] as [$type, $method, $amount]) {
            Document::create([
                'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
                'number' => 'RETURN-'.uniqid(), 'type' => $type, 'contact_id' => $contact->id,
                'document_date' => today()->toDateString(), 'subtotal' => $amount, 'total' => $amount,
                'payment_method' => $method, 'created_by' => $staff->id,
            ]);
        }

        $this->actingAs($staff)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('cash.on_hand', '92.00')
            ->assertJsonPath('cash.cash_received', '107.00')
            ->assertJsonPath('cash.cash_paid', '15.00');
    }
}
