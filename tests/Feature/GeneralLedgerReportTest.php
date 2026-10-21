<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Product;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_payment_and_expense_produce_balanced_postings_without_double_counting(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Customer A', 'type' => 'customer']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'sku' => 'LEDGER-1', 'name' => 'Test product', 'cost_price' => 30, 'sale_price' => 50]);
        $sale = $this->document($owner, $branch, $customer, 'sale', 'SAL-GL-1', '2026-10-01', 100, 70);
        $sale->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50, 'cost_price' => 30, 'line_total' => 100]);
        $sale->payments()->create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'amount' => 40, 'payment_method' => 'cash', 'kind' => 'initial', 'payment_date' => '2026-10-01', 'created_by' => $owner->id]);
        $sale->payments()->create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'amount' => 30, 'payment_method' => 'bank_transfer', 'kind' => 'payment', 'payment_date' => '2026-10-03', 'created_by' => $owner->id]);
        $expense = Expense::create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'category' => 'Rent', 'description' => 'Shop', 'amount' => 10, 'expense_date' => '2026-10-04', 'payment_method' => 'cash', 'created_by' => $owner->id]);

        $response = $this->actingAs($owner)->getJson('/api/reports?report=general_ledger&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('summary.total_debit', 240)
            ->assertJsonPath('summary.total_credit', 240)
            ->assertJsonPath('summary.entries', 10);

        $this->assertSame('Customer/Supplier', $response->json('columns.1'));
        $this->assertSame('Action/Invoice', $response->json('columns.7'));
        $receivables = collect($response->json('rows'))->where('account', '1100 · Accounts receivable')->values();
        $this->assertSame([100, 0, 0], $receivables->pluck('debit')->all());
        $this->assertSame([0, 40, 30], $receivables->pluck('credit')->all());
        $this->assertSame([100, 60, 30], $receivables->pluck('balance')->all());
        $this->assertSame('Customer A', $receivables[0]['customer_supplier']);
        $this->assertSame('SAL-GL-1', $receivables[0]['invoice']);
        $this->assertSame('document', $receivables[0]['source_type']);
        $this->assertSame($sale->id, $receivables[0]['source_id']);
        $this->assertSame($sale->id, $receivables[1]['source_id']);

        $expensePosting = collect($response->json('rows'))->firstWhere('reference', 'EXP-'.$expense->id);
        $this->assertSame('—', $expensePosting['customer_supplier']);
        $this->assertSame('expense', $expensePosting['source_type']);
        $this->assertSame($expense->id, $expensePosting['source_id']);
        $this->assertSame('Shop', $expensePosting['source_record']['description']);
    }

    public function test_account_filter_uses_prior_transactions_as_opening_balance_and_payment_dates(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Supplier A', 'type' => 'supplier']);
        $purchase = $this->document($owner, $branch, $supplier, 'purchase', 'PUR-GL-1', '2026-09-30', 100, 30);
        $purchase->purchasePayments()->create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'amount' => 30, 'payment_method' => 'cash', 'payment_date' => '2026-10-02', 'created_by' => $owner->id]);

        $this->actingAs($owner)->getJson('/api/reports?report=general_ledger&account=payables&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.reference', 'PUR-GL-1')
            ->assertJsonPath('rows.0.customer_supplier', 'Supplier A')
            ->assertJsonPath('rows.0.source_type', 'document')
            ->assertJsonPath('rows.0.source_id', $purchase->id)
            ->assertJsonPath('rows.0.debit', 30)
            ->assertJsonPath('rows.0.balance', -70)
            ->assertJsonPath('summary.opening_balance', -100)
            ->assertJsonPath('summary.closing_balance', -70);
    }

    public function test_returns_reverse_revenue_cost_and_inventory_with_balanced_totals(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Customer A', 'type' => 'customer']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'sku' => 'LEDGER-2', 'name' => 'Test product', 'cost_price' => 8, 'sale_price' => 20]);
        $return = $this->document($owner, $branch, $customer, 'sale_return', 'SRT-GL-1', '2026-10-05', 20, 0);
        $return->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20, 'cost_price' => 8, 'line_total' => 20]);

        $this->actingAs($owner)->getJson('/api/reports?report=general_ledger&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('summary.total_debit', 28)
            ->assertJsonPath('summary.total_credit', 28)
            ->assertJsonPath('summary.entries', 4);
    }

    public function test_purchase_return_reduces_supplier_payable_and_inventory(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Supplier A', 'type' => 'supplier']);
        $this->document($owner, $branch, $supplier, 'purchase', 'PUR-GL-2', '2026-10-01', 50, 0);
        $this->document($owner, $branch, $supplier, 'purchase_return', 'PRT-GL-2', '2026-10-02', 10, 0);

        $response = $this->actingAs($owner)->getJson('/api/reports?report=general_ledger&account=payables&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('summary.closing_balance', -40);

        $this->assertSame([0, 10], collect($response->json('rows'))->pluck('debit')->all());
        $this->assertSame([50, 0], collect($response->json('rows'))->pluck('credit')->all());
    }

    public function test_imported_invoice_payment_without_payment_row_is_not_lost(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Customer A', 'type' => 'customer']);
        $this->document($owner, $branch, $customer, 'sale', 'SAL-GL-OLD', '2026-10-01', 100, 25);

        $this->actingAs($owner)->getJson('/api/reports?report=general_ledger&account=unclassified&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.debit', 25)
            ->assertJsonPath('summary.closing_balance', 25);
    }

    public function test_report_excludes_other_branches_and_companies(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $otherBranch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Other', 'code' => 'OTHER', 'active' => true]);
        $otherOwner = User::factory()->admin()->create();
        $otherCompanyBranch = $otherOwner->organization->branches()->firstOrFail();
        foreach ([[$owner, $otherBranch], [$otherOwner, $otherCompanyBranch]] as [$user, $place]) {
            Expense::create(['organization_id' => $user->organization_id, 'branch_id' => $place->id, 'category' => 'Rent', 'description' => 'Private', 'amount' => 99, 'expense_date' => '2026-10-04', 'payment_method' => 'cash', 'created_by' => $user->id]);
        }
        Expense::create(['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'category' => 'Rent', 'description' => 'Visible', 'amount' => 10, 'expense_date' => '2026-10-04', 'payment_method' => 'cash', 'created_by' => $owner->id]);

        $this->actingAs($owner)->getJson('/api/reports?report=general_ledger&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('summary.total_debit', 10)
            ->assertJsonCount(2, 'rows');
    }

    public function test_staff_without_all_financial_permissions_cannot_open_the_general_ledger(): void
    {
        $staff = User::factory()->staff()->create([
            'permissions' => [Permission::Sales->value, Permission::Purchases->value, Permission::Expenses->value],
            'report_permissions' => ['general_ledger'],
        ]);

        $this->actingAs($staff)->getJson('/api/reports?report=general_ledger')->assertForbidden();
    }

    public function test_unknown_account_is_rejected(): void
    {
        $owner = User::factory()->admin()->create();

        $this->actingAs($owner)->getJson('/api/reports?report=general_ledger&account=private_account')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');
    }

    private function document(User $owner, Branch $branch, Contact $contact, string $type, string $number, string $date, int $total, int $paid): Document
    {
        return Document::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'number' => $number, 'type' => $type, 'contact_id' => $contact->id,
            'document_date' => $date, 'subtotal' => $total, 'total' => $total,
            'amount_paid' => $paid, 'created_by' => $owner->id,
        ]);
    }
}
