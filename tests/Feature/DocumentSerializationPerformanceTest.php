<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DocumentSerializationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_history_only_exposes_profit_to_the_owner(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $customer = Contact::create(['organization_id' => $owner->organization_id, 'type' => 'customer', 'name' => 'Customer']);
        $sale = Document::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'contact_id' => $customer->id, 'created_by' => $owner->id,
            'type' => 'sale', 'number' => 'PROFIT-1', 'document_date' => '2026-11-19',
            'subtotal' => 100, 'total' => 100, 'amount_paid' => 100,
        ]);
        $url = '/api/documents?type=sale&branch_id='.$branch->id;
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonStructure([['stock_profit', 'total_profit']]);

        $staff = User::factory()->staff()->create(['organization_id' => $owner->organization_id]);
        $manager = User::factory()->create(['organization_id' => $owner->organization_id]);
        foreach ([$staff, $manager] as $user) {
            $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('0.id', $sale->id)
                ->assertJsonMissingPath('0.stock_profit')->assertJsonMissingPath('0.total_profit');
            $this->getJson($url.'&paginate=1&date_from=2026-11-19')->assertOk()->assertJsonPath('data.0.id', $sale->id)
                ->assertJsonMissingPath('data.0.stock_profit')->assertJsonMissingPath('data.0.total_profit');
        }
    }

    #[TestWith(['/api/documents?paginate=1&type=sale&per_page=30'])]
    #[TestWith(['/api/documents'])]
    #[TestWith(['/api/documents', 400])]
    #[TestWith(['/api/warranties'])]
    #[TestWith(['/api/movements'])]
    public function test_invoice_history_serializes_with_bounded_queries_and_correct_return_adjusted_balances(string $endpoint, int $invoiceCount = 30): void
    {
        $this->travelTo(now()->setDate(2026, 11, 19)->startOfDay());
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'type' => 'customer', 'name' => 'Shared customer']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Warranty item', 'unit' => 'pc']);
        $ids = [];
        for ($index = 0; $index < $invoiceCount; $index++) {
            $sale = Document::create([
                'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
                'contact_id' => $contact->id, 'created_by' => $owner->id,
                'type' => 'sale', 'number' => 'PERF-'.$index, 'document_date' => '2026-11-19',
                'subtotal' => 100, 'total' => 100, 'amount_paid' => 20,
            ]);
            $sale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'cost_price' => 40, 'line_total' => 100, 'warranty_months' => 12]);
            StockMovement::create([
                'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
                'product_id' => $product->id, 'document_id' => $sale->id, 'type' => 'sale',
                'quantity_change' => -1, 'balance_after' => 1000 - $index, 'created_by' => $owner->id,
            ]);
            $ids[] = $sale->id;
        }
        Document::create([
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by' => $owner->id,
            'type' => 'sale_return', 'number' => 'PERF-RETURN', 'document_date' => '2026-11-19',
            'sale_id' => $ids[0], 'subtotal' => 130, 'total' => 130, 'payment_method' => 'credit',
        ]);
        $this->actingAs($owner);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->getJson($endpoint);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk();
        $this->assertLessThanOrEqual(60, count($queries), 'Invoice references must not trigger per-row history scans or lazy item queries.');
        $rows = collect($response->json('data') ?? $response->json());
        if (str_starts_with($endpoint, '/api/documents')) {
            $sales = $rows->where('type', 'sale')->keyBy('id');
            $this->assertCount($invoiceCount, $sales);
            $this->assertSame('0.00', $sales[$ids[0]]['balance_due']);
            $this->assertSame('30.00', $sales[$ids[1]]['balance_due']);
            $this->assertSame('80.00', $sales[$ids[2]]['balance_due']);
            $this->assertSame('paid', $sales[$ids[0]]['payment_status']);
            $this->assertSame('60.00', $sales[$ids[2]]['stock_profit']);
            $this->assertNotEmpty($sales[$ids[2]]['correction_version']);
        } else {
            $this->assertCount(30, $rows);
            $this->assertSame('PERF-29', $rows->keyBy('document.id')->get($ids[29])['document']['number']);
        }
    }

    public function test_filtered_invoice_balance_includes_other_branches_and_excludes_cash_refunds_and_other_customers(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $otherBranch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Other branch', 'code' => 'OTHER', 'active' => true]);
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'type' => 'customer', 'name' => 'Credit customer']);
        $otherContact = Contact::create(['organization_id' => $owner->organization_id, 'type' => 'customer', 'name' => 'Other customer']);
        $base = [
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by' => $owner->id,
            'type' => 'sale', 'document_date' => '2026-11-19', 'subtotal' => 100, 'total' => 100, 'amount_paid' => 20,
        ];
        $original = Document::create([...$base, 'number' => 'ORIGINAL', 'branch_id' => $otherBranch->id, 'document_date' => '2026-11-01']);
        $target = Document::create([...$base, 'number' => 'DISPLAY']);
        Document::create([...$base, 'number' => 'CREDIT', 'type' => 'sale_return', 'sale_id' => $original->id, 'total' => 130, 'payment_method' => null]);
        Document::create([...$base, 'number' => 'CASH-REFUND', 'type' => 'sale_return', 'sale_id' => $target->id, 'total' => 40, 'payment_method' => 'cash']);
        Document::create([...$base, 'number' => 'FOREIGN-CREDIT', 'type' => 'sale_return', 'contact_id' => $otherContact->id, 'total' => 500, 'payment_method' => 'credit']);
        $foreignOwner = User::factory()->admin()->create();
        Document::create([...$base, 'number' => 'FOREIGN-ORG', 'type' => 'sale_return', 'organization_id' => $foreignOwner->organization_id, 'total' => 500, 'payment_method' => 'credit']);

        $this->actingAs($owner)->getJson('/api/documents?paginate=1&type=sale&date_from=2026-11-19&search=DISPLAY&branch_id='.$branch->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.balance_due', '30.00')->assertJsonPath('data.0.payment_status', 'partial');
        $this->assertSame('30.00', $target->fresh()->balance_due);
    }
}
