<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_groups_products_and_reverses_returns_with_allocated_discount_and_interest(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $ghee = $this->product($owner, 'Ghee Premium', 'GHEE');
        $oil = $this->product($owner, 'Mustard Oil', 'OIL');
        $sale = $this->document($owner, $branch, 'sale', '2026-10-10', 200, 10, 20);
        $sale->items()->create(['product_id' => $ghee->id, 'quantity' => 2, 'unit_price' => 50, 'cost_price' => 30, 'line_total' => 100]);
        $sale->items()->create(['product_id' => $oil->id, 'quantity' => 1, 'unit_price' => 100, 'cost_price' => 80, 'line_total' => 100]);
        $return = $this->document($owner, $branch, 'sale_return', '2026-10-11', 50);
        $return->items()->create(['product_id' => $ghee->id, 'quantity' => 1, 'unit_price' => 50, 'cost_price' => 30, 'line_total' => 50]);

        $this->actingAs($owner)->getJson('/api/reports?report=product_ledger&branch_id='.$branch->id.'&date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('branch_name', $branch->name)
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.0.product', 'Ghee Premium')
            ->assertJsonPath('rows.0.quantity', 1)
            ->assertJsonPath('rows.0.total_sales', 45)
            ->assertJsonPath('rows.0.costing', 30)
            ->assertJsonPath('rows.0.stock_profit', 15)
            ->assertJsonPath('rows.0.installment_profit', 10)
            ->assertJsonPath('rows.0.period_profit', 25)
            ->assertJsonPath('rows.1.product', 'Mustard Oil')
            ->assertJsonPath('rows.1.period_profit', 25)
            ->assertJsonPath('summary.quantity', 2)
            ->assertJsonPath('summary.total_sales', 140)
            ->assertJsonPath('summary.costing', 110)
            ->assertJsonPath('summary.stock_profit', 30)
            ->assertJsonPath('summary.installment_profit', 20)
            ->assertJsonPath('summary.period_profit', 50);
    }

    public function test_search_and_dates_only_include_matching_products_in_the_period(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $ghee = $this->product($owner, 'Ghee Premium', 'GHEE');
        $oil = $this->product($owner, 'Mustard Oil', 'OIL');
        $sale = $this->document($owner, $branch, 'sale', '2026-10-10', 150);
        $sale->items()->create(['product_id' => $ghee->id, 'quantity' => 1, 'unit_price' => 50, 'cost_price' => 30, 'line_total' => 50]);
        $sale->items()->create(['product_id' => $oil->id, 'quantity' => 1, 'unit_price' => 100, 'cost_price' => 80, 'line_total' => 100]);
        $olderSale = $this->document($owner, $branch, 'sale', '2026-09-10', 70);
        $olderSale->items()->create(['product_id' => $ghee->id, 'quantity' => 1, 'unit_price' => 70, 'cost_price' => 30, 'line_total' => 70]);

        $this->actingAs($owner)->getJson('/api/reports?report=product_ledger&branch_id='.$branch->id.'&date_from=2026-10-01&date_to=2026-10-31&search=GHEE')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.product', 'Ghee Premium')
            ->assertJsonPath('summary.total_sales', 50)
            ->assertJsonPath('summary.period_profit', 20);
    }

    public function test_staff_cannot_view_unassigned_branch_or_all_branches_with_only_one_assignment(): void
    {
        $owner = User::factory()->admin()->create();
        $main = $owner->organization->branches()->firstOrFail();
        $second = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Second branch', 'code' => 'SECOND', 'active' => true]);
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Sales->value],
            'report_permissions' => ['product_ledger'],
        ]);
        $staff->accessibleBranches()->sync([$main->id]);

        $this->actingAs($staff)->getJson('/api/reports?report=product_ledger&branch_id='.$main->id)->assertOk();
        $this->getJson('/api/reports?report=product_ledger&branch_id=all')->assertForbidden();
        $this->getJson('/api/reports?report=product_ledger&branch_id='.$second->id)
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_all_branch_report_only_includes_branches_assigned_to_staff(): void
    {
        $owner = User::factory()->admin()->create();
        $main = $owner->organization->branches()->firstOrFail();
        $second = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Second branch', 'code' => 'SECOND', 'active' => true]);
        $private = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Private branch', 'code' => 'PRIVATE', 'active' => true]);
        $product = $this->product($owner, 'Ghee Premium', 'GHEE');
        foreach ([$main, $second, $private] as $branch) {
            $sale = $this->document($owner, $branch, 'sale', '2026-10-10', 50);
            $sale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50, 'cost_price' => 30, 'line_total' => 50]);
        }
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Sales->value],
            'report_permissions' => ['product_ledger'],
        ]);
        $staff->accessibleBranches()->sync([$main->id, $second->id]);

        $this->actingAs($staff)->getJson('/api/reports?report=product_ledger&branch_id=all')
            ->assertOk()
            ->assertJsonPath('branch_name', 'All permitted branches')
            ->assertJsonPath('summary.quantity', 2)
            ->assertJsonPath('summary.total_sales', 100);
    }

    private function product(User $owner, string $name, string $sku): Product
    {
        return Product::create(['organization_id' => $owner->organization_id, 'sku' => $sku, 'name' => $name, 'cost_price' => 30, 'sale_price' => 50]);
    }

    private function document(User $owner, Branch $branch, string $type, string $date, int $subtotal, int $discount = 0, int $interest = 0): Document
    {
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Ledger customer', 'type' => 'customer']);

        return Document::create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branch->id,
            'number' => uniqid('PLD-'),
            'type' => $type,
            'contact_id' => $customer->id,
            'document_date' => $date,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'interest_amount' => $interest,
            'total' => $subtotal - $discount + $interest,
            'created_by' => $owner->id,
        ]);
    }
}
