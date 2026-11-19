<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_large_history_without_loading_every_invoice_and_keeps_return_adjusted_dues(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 19)->startOfDay());
        $owner = User::factory()->admin()->create();
        $owner->organization->update(['system_settings' => ['store' => ['allow_cash_handover' => true]]]);
        $branch = $owner->organization->branches()->firstOrFail();
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'type' => 'customer', 'name' => 'Dashboard customer']);
        $product = Product::create(['organization_id' => $owner->organization_id, 'name' => 'Dashboard product', 'unit' => 'pc']);
        $base = [
            'organization_id' => $owner->organization_id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by' => $owner->id,
            'type' => 'sale', 'document_date' => '2026-11-01', 'subtotal' => 100, 'total' => 100,
            'amount_paid' => 100, 'created_at' => now(), 'updated_at' => now(),
        ];
        $rows = [];
        for ($index = 0; $index < 400; $index++) {
            $rows[] = [...$base, 'number' => 'PAID-'.$index];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('documents')->insert($chunk);
        }
        $dueIds = [];
        for ($index = 0; $index < 30; $index++) {
            $dueIds[] = Document::create([...$base, 'number' => 'DUE-'.$index, 'amount_paid' => 20])->id;
        }
        $items = Document::query()->pluck('id')->map(fn ($id): array => [
            'document_id' => $id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
        ])->all();
        foreach (array_chunk($items, 100) as $chunk) {
            DB::table('document_items')->insert($chunk);
        }
        $return = Document::create([...$base, 'number' => 'CREDIT', 'type' => 'sale_return', 'sale_id' => $dueIds[0], 'amount_paid' => 0, 'total' => 130, 'payment_method' => 'credit']);
        $return->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 130, 'line_total' => 130]);
        $this->actingAs($owner);
        $hydratedInvoices = 0;
        Event::listen('eloquent.retrieved: '.Document::class, function () use (&$hydratedInvoices): void {
            $hydratedInvoices++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->getJson('/api/overview?branch_id='.$branch->id);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            Event::forget('eloquent.retrieved: '.Document::class);
        }

        $response->assertOk()->assertJsonPath('cash.paid_balance', '40600.00')->assertJsonPath('sales_total', 42870)
            ->assertJsonPath('monthly_totals.10.sales', 42870)
            ->assertJsonPath('highest_selling_products.0.quantity', 429)
            ->assertJsonPath('customer_due_list.0.amount_due', '2270.00')
            ->assertJsonPath('customer_due_list.0.invoices_count', 29)
            ->assertJsonCount(6, 'recent_documents');
        $this->assertLessThanOrEqual(55, count($queries), 'Dashboard query count must stay bounded as invoice history grows.');
        $this->assertLessThanOrEqual(50, $hydratedInvoices, 'Paid invoice history must be aggregated in the database rather than hydrated for chart totals.');
    }
}
