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

    public function test_full_handover_moves_customer_payments_even_when_cash_collections_are_lower(): void
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
            ->assertOk()->assertJsonPath('cash.on_hand', '150.00')
            ->assertJsonPath('cash.physical_cash', '70.00')
            ->assertJsonPath('cash.cash_received', '100.00')
            ->assertJsonPath('cash.cash_paid', '30.00');
        $this->postJson('/api/cash/transfer', ['branch_id' => $branch->id, 'notes' => 'End of shift'])
            ->assertCreated()->assertJsonPath('movement.amount', '150.00')->assertJsonPath('cash.on_hand', '0.00');
        $this->postJson('/api/cash/transfer', ['branch_id' => $branch->id])->assertUnprocessable();
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 1])->assertForbidden();

        $this->actingAs($owner)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('cash.on_hand', '150.00')
            ->assertJsonPath('cash.received_transfers', '150.00')
            ->assertJsonPath('cash.staff_balances.0.on_hand', '0.00');
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 151])->assertUnprocessable();
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 25, 'notes' => 'Bank deposit'])
            ->assertCreated()->assertJsonPath('cash.on_hand', '125.00')
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

    public function test_refunded_returns_reduce_paid_balance_but_credit_returns_do_not(): void
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
            ->assertOk()->assertJsonPath('cash.on_hand', '85.00')
            ->assertJsonPath('cash.physical_cash', '92.00')
            ->assertJsonPath('cash.cash_received', '107.00')
            ->assertJsonPath('cash.cash_paid', '15.00');
    }

    public function test_owner_can_withdraw_all_customer_payments_despite_negative_physical_cash(): void
    {
        [$owner, , $branch] = $this->users();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $this->payment($owner, $branch->id, '94744.25', 'card');
        Expense::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'category' => 'Other', 'description' => 'Cash expense', 'amount' => '14893.30',
            'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('sales_total', 94744.25)
            ->assertJsonPath('cash.on_hand', '94744.25')
            ->assertJsonPath('cash.physical_cash', '-14893.30');
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => '94744.25'])
            ->assertCreated()->assertJsonPath('cash.on_hand', '0.00');
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => '0.01'])->assertUnprocessable();
    }

    public function test_return_reduces_original_sellers_balance_even_when_another_user_processes_it(): void
    {
        [$owner, $staff, $branch] = $this->users();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $this->payment($staff, $branch->id, '100.00', 'card');
        $sale = Document::query()->where('created_by', $staff->id)->firstOrFail();
        Document::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'number' => 'RETURN-STAFF', 'type' => 'sale_return', 'contact_id' => $sale->contact_id,
            'sale_id' => $sale->id, 'document_date' => today()->toDateString(),
            'subtotal' => '30.00', 'total' => '30.00', 'payment_method' => 'cash',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($staff)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('cash.on_hand', '70.00');
        $this->actingAs($owner)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('cash.on_hand', '0.00');
    }

    public function test_unpaid_invoice_does_not_become_withdrawable_until_customer_pays(): void
    {
        [$owner, , $branch] = $this->users();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Due customer', 'type' => 'customer']);
        $sale = Document::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'number' => 'DUE-1', 'type' => 'sale', 'contact_id' => $customer->id,
            'document_date' => today()->toDateString(), 'subtotal' => '100.00',
            'total' => '100.00', 'amount_paid' => '0.00', 'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('sales_total', 100)->assertJsonPath('cash.on_hand', '0.00');
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 100])->assertUnprocessable();

        $sale->payments()->create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'amount' => '40.00', 'payment_method' => 'bank_transfer', 'kind' => 'payment',
            'payment_date' => today()->toDateString(), 'created_by' => $owner->id,
        ]);
        $this->actingAs($owner)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('cash.on_hand', '40.00');
        $this->postJson('/api/cash/withdraw', ['branch_id' => $branch->id, 'amount' => 40])
            ->assertCreated()->assertJsonPath('cash.on_hand', '0.00');
    }

    public function test_legacy_invoice_paid_amount_is_counted_once_when_no_payment_rows_exist(): void
    {
        [$owner, , $branch] = $this->users();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Legacy customer', 'type' => 'customer']);
        Document::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'number' => 'LEGACY-PAID', 'type' => 'sale', 'contact_id' => $customer->id,
            'document_date' => today()->toDateString(), 'subtotal' => '100.00',
            'total' => '100.00', 'amount_paid' => '60.00', 'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)->getJson('/api/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('cash.on_hand', '60.00');
        $this->getJson('/api/reports?report=cash_movement&branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('summary.customer_payments', 60)
            ->assertJsonPath('summary.closing_paid_balance', 60);
    }
}
