<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceDelivery;
use App\Models\SupplierTtEntry;
use App\Models\User;
use App\Services\SupplierAdvanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierAdvanceReportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_history_is_aggregated_in_sql_and_only_selected_period_details_are_returned(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $base = ['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'company_name' => 'ACI Salt', 'created_by' => $owner->id];
        $rows = array_fill(0, 1200, [...$base, 'advance_date' => '2025-01-01', 'amount' => '1.25', 'payment_method' => 'bank_transfer', 'notes' => str_repeat('Old notes ', 100)]);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('supplier_advances')->insert($chunk);
        }
        SupplierAdvanceDelivery::create([...$base, 'delivery_date' => '2025-02-01', 'installment_code' => 'OLD', 'product_details' => 'Old delivery', 'amount' => '100.50']);
        SupplierTtEntry::create([...$base, 'entry_date' => '2025-03-01', 'entry_type' => 'credit', 'amount' => 10]);
        $advance = SupplierAdvance::create([...$base, 'advance_date' => '2026-10-01', 'amount' => 200, 'payment_method' => 'bank_transfer']);
        SupplierAdvanceDelivery::create([...$base, 'delivery_date' => '2026-11-01', 'installment_code' => 'CURRENT', 'product_details' => 'Current delivery', 'amount' => 50]);
        SupplierAdvance::create([...$base, 'advance_date' => '2026-11-02', 'amount' => 999999, 'payment_method' => 'bank_transfer']);
        $otherBranch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Other', 'code' => 'OTHER', 'active' => true]);
        SupplierAdvance::create([...$base, 'branch_id' => $otherBranch->id, 'advance_date' => '2025-01-01', 'amount' => 999999, 'payment_method' => 'bank_transfer']);
        $otherOwner = User::factory()->admin()->create();
        SupplierAdvance::create([...$base, 'organization_id' => $otherOwner->organization_id, 'branch_id' => $otherOwner->organization->branches()->firstOrFail()->id, 'advance_date' => '2025-01-01', 'amount' => 999999, 'payment_method' => 'bank_transfer']);

        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $result = app(SupplierAdvanceReport::class)->build($owner->organization_id, $branch->id, '2026-10-01', '2026-11-01');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(2, $queries);
        $this->assertStringContainsString('SUM(', $queries[0]['query']);
        $this->assertStringContainsString('group by', $queries[0]['query']);
        $this->assertStringNotContainsString('notes', $queries[0]['query']);
        $this->assertStringNotContainsString('notes', $queries[1]['query']);
        $this->assertSame(1510.0, $result['summary']['opening_paid']);
        $this->assertSame(100.5, $result['summary']['opening_received']);
        $this->assertSame(200.0, $result['summary']['advance_sent']);
        $this->assertSame(50.0, $result['summary']['products_received']);
        $this->assertSame(1559.5, $result['summary']['closing_balance']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame('ADV-'.$advance->id, $result['rows'][0]['reference']);
        $this->assertSame(1609.5, $result['rows'][0]['balance']);
        $this->assertSame(1559.5, $result['rows'][1]['balance']);
        $this->assertCount(1, $result['company_totals']);
    }

    public function test_opening_balances_group_legacy_delivery_company_expressions_without_unaggregated_columns(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $base = ['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'created_by' => $owner->id];
        $advance = SupplierAdvance::create([...$base, 'company_name' => 'Legacy company', 'advance_date' => '2026-10-01', 'amount' => 100, 'payment_method' => 'cash']);
        SupplierAdvanceDelivery::create([...$base, 'company_name' => null, 'supplier_advance_id' => $advance->id, 'delivery_date' => '2026-10-02', 'installment_code' => 'LEGACY', 'product_details' => 'Salt', 'amount' => 30]);
        SupplierAdvanceDelivery::create([...$base, 'company_name' => 'Other company', 'delivery_date' => '2026-10-02', 'installment_code' => 'OTHER', 'product_details' => 'Salt', 'amount' => 20]);
        SupplierTtEntry::create([...$base, 'company_name' => 'legacy company', 'entry_date' => '2026-10-03', 'entry_type' => 'commission', 'amount' => 5]);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $result = app(SupplierAdvanceReport::class)->build($owner->organization_id, $branch->id, '2026-11-01', '2026-11-01');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertStringContainsString("MIN(COALESCE(NULLIF(d.company_name, ''), a.company_name, '')) AS company", $queries[0]['query']);
        $this->assertSame(105.0, $result['summary']['opening_paid']);
        $this->assertSame(50.0, $result['summary']['opening_received']);
        $this->assertSame(55.0, $result['summary']['closing_balance']);
        $this->assertCount(0, $result['rows']);
        $companies = collect($result['company_totals'])->keyBy('company');
        $this->assertSame(75.0, $companies['Legacy company']['closing_balance']);
        $this->assertSame(20.0, $companies['Other company']['due_balance']);
    }

    public function test_literal_company_search_and_legacy_delivery_fallback_are_preserved(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $base = ['organization_id' => $owner->organization_id, 'branch_id' => $branch->id, 'created_by' => $owner->id];
        $advance = SupplierAdvance::create([...$base, 'company_name' => 'ACI 10% Salt', 'advance_date' => '2026-10-01', 'amount' => 100, 'payment_method' => 'cash']);
        SupplierAdvanceDelivery::create([...$base, 'company_name' => null, 'supplier_advance_id' => $advance->id, 'delivery_date' => '2026-10-01', 'installment_code' => 'LEGACY', 'product_details' => 'Salt', 'amount' => 30]);
        SupplierAdvance::create([...$base, 'company_name' => 'Other company', 'advance_date' => '2026-10-01', 'amount' => 999, 'payment_method' => 'cash']);
        SupplierTtEntry::create([...$base, 'company_name' => 'aci 10% salt', 'entry_date' => '2026-10-01', 'entry_type' => 'incentive', 'amount' => 5]);
        $result = app(SupplierAdvanceReport::class)->build($owner->organization_id, $branch->id, '2026-10-01', '2026-11-01', '%');
        $this->assertCount(3, $result['rows']);
        $this->assertSame('Incentive', $result['rows'][0]['particular']);
        $this->assertSame('Advance sent', $result['rows'][1]['particular']);
        $this->assertSame('Products received', $result['rows'][2]['particular']);
        $this->assertSame('ACI 10% Salt', $result['rows'][2]['company']);
        $this->assertSame('ADV-'.$advance->id, $result['rows'][2]['reference']);
        $this->assertSame(75.0, $result['summary']['closing_balance']);
        $this->assertCount(1, $result['company_totals']);
    }
}
