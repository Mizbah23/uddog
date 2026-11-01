<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DocumentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(User $owner, string $type = 'sale', ?string $date = null, ?int $branchId = null): Document
    {
        $contact = Contact::create(['organization_id' => $owner->organization_id, 'type' => 'both', 'name' => 'History customer']);

        return Document::create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branchId ?? $owner->organization->branches()->where('is_default', true)->value('id'),
            'type' => $type, 'number' => 'HISTORY-'.fake()->unique()->numerify('########'),
            'contact_id' => $contact->id, 'document_date' => $date ?? now('Asia/Dhaka')->toDateString(), 'subtotal' => 10, 'total' => 10,
            'created_by' => $owner->id,
        ]);
    }

    #[TestWith(['sale'])]
    #[TestWith(['purchase'])]
    public function test_history_is_bounded_and_pages_have_stable_order_without_duplicates(string $type): void
    {
        $owner = User::factory()->admin()->create();
        $ids = [];
        for ($i = 0; $i < 53; $i++) {
            $ids[] = $this->invoice($owner, $type)->id;
        }
        $this->actingAs($owner);
        $first = $this->getJson('/api/documents?paginate=1&type='.$type)->assertOk()
            ->assertJsonCount(25, 'data')->assertJsonPath('pagination.total', 53)
            ->assertJsonPath('pagination.last_page', 3)->assertJsonPath('data.0.id', $ids[52])->json('data');
        $second = $this->getJson('/api/documents?paginate=1&type='.$type.'&page=2')->assertOk()
            ->assertJsonCount(25, 'data')->assertJsonPath('data.0.id', $ids[27])->json('data');
        $this->assertSame([], array_values(array_intersect(array_column($first, 'id'), array_column($second, 'id'))));
        $this->getJson('/api/documents?paginate=1&type='.$type.'&page=3')->assertOk()->assertJsonCount(3, 'data');
    }

    #[TestWith(['sale'])]
    #[TestWith(['purchase'])]
    public function test_dates_are_inclusive_and_search_is_applied_before_pagination_with_tenant_branch_and_type_isolation(string $type): void
    {
        $owner = User::factory()->admin()->create();
        $start = $this->invoice($owner, $type, '2026-10-01');
        $end = $this->invoice($owner, $type, '2026-10-31');
        $end->contact->update(['name' => 'Find me']);
        $this->invoice($owner, $type, '2026-09-30');
        $this->invoice($owner, $type, '2026-11-01');
        $this->invoice($owner, $type === 'sale' ? 'purchase' : 'sale');
        $otherBranch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Other', 'code' => 'OTHER', 'active' => true]);
        $this->invoice($owner, $type, branchId: $otherBranch->id);
        $this->invoice(User::factory()->admin()->create(), $type);
        $base = '/api/documents?paginate=1&type='.$type.'&date_from=2026-10-01&date_to=2026-10-31';
        $this->actingAs($owner)->getJson($base)->assertOk()->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('data.0.id', $end->id)->assertJsonPath('data.1.id', $start->id);
        $this->getJson($base.'&search=Find')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $end->id);
        $this->getJson($base.'&search='.$start->number)->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $start->id);
        $this->getJson('/api/documents?paginate=1&type='.$type.'&date_to=2026-09-30')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->getJson($base.'&search=missing')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.total', 0);
    }

    #[TestWith(['sale'])]
    #[TestWith(['purchase'])]
    public function test_unfiltered_history_defaults_to_today_in_bangladesh_and_explicit_dates_override_it(string $type): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 20:00:00', 'UTC'));
        $owner = User::factory()->admin()->create();
        $current = $this->invoice($owner, $type, '2026-11-02');
        $previous = $this->invoice($owner, $type, '2026-11-01');
        $this->actingAs($owner)->getJson('/api/documents?paginate=1&type='.$type)->assertOk()
            ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $current->id);
        $this->getJson('/api/documents?paginate=1&type='.$type.'&search='.$previous->number)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/documents?paginate=1&type='.$type.'&date_from=2026-11-01&date_to=2026-11-01&search='.$previous->number)
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $previous->id);
    }

    public function test_invalid_date_ranges_and_unbounded_page_sizes_are_rejected(): void
    {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner)->getJson('/api/documents?paginate=1&per_page=10000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/documents?paginate=1&date_from=2026-11-01&date_to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/api/documents?paginate=1&date_from=invalid')->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson('/api/documents?paginate=1&page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_paginated_history_keeps_transaction_permission_checks(): void
    {
        $owner = User::factory()->admin()->create();
        $staff = User::factory()->for($owner->organization)->create(['permissions' => ['sales']]);
        $this->actingAs($staff)->getJson('/api/documents?paginate=1&type=purchase')->assertForbidden();
        $this->getJson('/api/documents?paginate=1&type=sale')->assertOk();
    }
}
