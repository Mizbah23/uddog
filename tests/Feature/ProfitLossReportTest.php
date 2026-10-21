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

class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_compare_one_branch_with_all_branches_and_expenses_are_grouped_by_account(): void
    {
        $owner = User::factory()->admin()->create();
        $main = $owner->organization->branches()->firstOrFail();
        $second = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Second branch', 'code' => 'SECOND', 'active' => true]);
        $product = Product::create(['organization_id' => $owner->organization_id, 'sku' => 'PL-1', 'name' => 'Test product', 'cost_price' => 60, 'sale_price' => 100]);
        $mainSale = $this->document($owner, $main, 'sale', 'SAL-PL-1', 95, 10, 5);
        $mainSale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'cost_price' => 60, 'line_total' => 100]);
        $secondSale = $this->document($owner, $second, 'sale', 'SAL-PL-2', 80);
        $secondSale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 80, 'cost_price' => 50, 'line_total' => 80]);
        $return = $this->document($owner, $main, 'sale_return', 'SRT-PL-1', 20);
        $return->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20, 'cost_price' => 12, 'line_total' => 20]);
        $this->expense($owner, $main, 'Rent', 10);
        $this->expense($owner, $main, 'Utilities', 5);
        $this->expense($owner, $second, 'Rent', 7);

        $this->actingAs($owner)->getJson('/api/reports?report=profit_loss&branch_id=all&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('branch_name', 'All permitted branches')
            ->assertJsonPath('summary.total_sales', 175)
            ->assertJsonPath('summary.sales_returns', 20)
            ->assertJsonPath('summary.net_sales', 155)
            ->assertJsonPath('summary.cost_of_sales', 98)
            ->assertJsonPath('summary.gross_profit', 57)
            ->assertJsonPath('summary.operating_expenses', 22)
            ->assertJsonPath('summary.total_costs_and_expenses', 120)
            ->assertJsonPath('summary.net_profit', 35)
            ->assertJsonPath('income.0.account', 'Total sales')
            ->assertJsonPath('income.0.amount', 175)
            ->assertJsonPath('income.1.account', 'Sales returns')
            ->assertJsonPath('income.1.amount', -20)
            ->assertJsonPath('expenses.0.account', 'Cost of sales')
            ->assertJsonPath('expenses.0.amount', 98)
            ->assertJsonPath('expenses.1.account', 'Rent')
            ->assertJsonPath('expenses.1.amount', 17)
            ->assertJsonPath('expenses.2.account', 'Utilities')
            ->assertJsonPath('expenses.2.amount', 5);

        $this->getJson('/api/reports?report=profit_loss&branch_id='.$second->id.'&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('branch_name', 'Second branch')
            ->assertJsonPath('summary.gross_profit', 30)
            ->assertJsonPath('summary.operating_expenses', 7)
            ->assertJsonPath('summary.net_profit', 23);
    }

    public function test_sales_tax_is_shown_as_an_adjustment_without_inflating_profit(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $product = Product::create(['organization_id' => $owner->organization_id, 'sku' => 'PL-TAX', 'name' => 'Taxed product', 'cost_price' => 60, 'sale_price' => 100]);
        $sale = $this->document($owner, $branch, 'sale', 'SAL-PL-TAX', 110, tax: 10);
        $sale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'cost_price' => 60, 'line_total' => 100]);

        $this->actingAs($owner)->getJson('/api/reports?report=profit_loss&branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('income.0.amount', 110)
            ->assertJsonPath('expenses.0.account', 'Cost of sales')
            ->assertJsonPath('expenses.0.amount', 60)
            ->assertJsonPath('expenses.1.account', 'Tax & sales adjustments')
            ->assertJsonPath('expenses.1.amount', 10)
            ->assertJsonPath('summary.net_profit', 40);
    }

    public function test_single_branch_staff_cannot_use_all_or_another_branch(): void
    {
        $owner = User::factory()->admin()->create();
        $main = $owner->organization->branches()->firstOrFail();
        $second = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Second branch', 'code' => 'SECOND', 'active' => true]);
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Sales->value, Permission::Expenses->value],
            'report_permissions' => ['profit_loss'],
        ]);
        $staff->accessibleBranches()->sync([$main->id]);
        $this->expense($owner, $main, 'Rent', 10);
        $this->expense($owner, $second, 'Rent', 90);

        $this->actingAs($staff)->getJson('/api/reports?report=profit_loss&branch_id='.$main->id)
            ->assertOk()
            ->assertJsonPath('summary.operating_expenses', 10);
        $this->getJson('/api/reports?report=profit_loss&branch_id=all')->assertForbidden();
        $this->getJson('/api/reports?report=profit_loss&branch_id='.$second->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
        $this->getJson('/api/reports?report=profit_loss&branch_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_all_branch_report_for_staff_only_includes_assigned_branches(): void
    {
        $owner = User::factory()->admin()->create();
        $main = $owner->organization->branches()->firstOrFail();
        $second = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Second branch', 'code' => 'SECOND', 'active' => true]);
        $private = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Private branch', 'code' => 'PRIVATE', 'active' => true]);
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Sales->value, Permission::Expenses->value],
            'report_permissions' => ['profit_loss'],
        ]);
        $staff->accessibleBranches()->sync([$main->id, $second->id]);
        $this->expense($owner, $main, 'Rent', 10);
        $this->expense($owner, $second, 'Rent', 20);
        $this->expense($owner, $private, 'Rent', 90);

        $this->actingAs($staff)->getJson('/api/reports?report=profit_loss&branch_id=all')
            ->assertOk()
            ->assertJsonPath('summary.operating_expenses', 30)
            ->assertJsonPath('expenses.0.amount', 30);
    }

    private function document(User $owner, Branch $branch, string $type, string $number, int $total, int $discount = 0, int $interest = 0, int $tax = 0): Document
    {
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Report customer', 'type' => 'customer']);

        return Document::create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branch->id,
            'number' => $number,
            'type' => $type,
            'contact_id' => $customer->id,
            'document_date' => '2026-10-10',
            'subtotal' => $total,
            'discount' => $discount,
            'tax' => $tax,
            'interest_amount' => $interest,
            'total' => $total,
            'created_by' => $owner->id,
        ]);
    }

    private function expense(User $owner, Branch $branch, string $category, int $amount): void
    {
        Expense::create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branch->id,
            'category' => $category,
            'description' => $category,
            'amount' => $amount,
            'expense_date' => '2026-10-10',
            'payment_method' => 'cash',
            'created_by' => $owner->id,
        ]);
    }
}
