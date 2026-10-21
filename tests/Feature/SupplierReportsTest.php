<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\User;
use App\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_suppliers_report_includes_opening_payable_and_returns_in_the_selected_branch(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $otherBranch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Other branch', 'code' => 'OTHER', 'active' => true]);
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Parts Supplier', 'type' => 'supplier', 'opening_payable' => 20]);
        $purchase = $this->document($owner, $branch, $supplier, 'purchase', 'PUR-1', 200, 50);
        $this->document($owner, $branch, $supplier, 'purchase_return', 'PRT-1', 30, 0, $purchase);
        $this->document($owner, $otherBranch, $supplier, 'purchase', 'PUR-OTHER', 100, 0);

        $this->actingAs($owner)->getJson("/api/reports?report=suppliers&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.supplier', 'Parts Supplier')
            ->assertJsonPath('rows.0.purchases_count', 1)
            ->assertJsonPath('rows.0.purchases', 200)
            ->assertJsonPath('rows.0.returns', 30)
            ->assertJsonPath('rows.0.paid', 50)
            ->assertJsonPath('rows.0.opening_payable', 20)
            ->assertJsonPath('rows.0.due', 140)
            ->assertJsonPath('summary.due', 140);
    }

    public function test_supplier_ledger_shows_purchase_payment_and_return_and_opens_the_linked_invoice(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Parts Supplier', 'type' => 'supplier']);
        $purchase = $this->document($owner, $branch, $supplier, 'purchase', 'PUR-2', 200, 50);
        $return = $this->document($owner, $branch, $supplier, 'purchase_return', 'PRT-2', 30, 0, $purchase);
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Contacts->value],
            'report_permissions' => ['supplier_ledger'],
        ]);

        $this->actingAs($staff)->getJson("/api/reports?report=supplier_ledger&branch_id={$branch->id}&contact_id={$supplier->id}")
            ->assertOk()
            ->assertJsonCount(2, 'rows')
            ->assertJsonPath('rows.0.reference', 'PUR-2')
            ->assertJsonPath('rows.0.document_id', $purchase->id)
            ->assertJsonPath('rows.0.debit', 50)
            ->assertJsonPath('rows.0.credit', 200)
            ->assertJsonPath('rows.0.balance', 120)
            ->assertJsonPath('rows.1.reference', 'PRT-2')
            ->assertJsonPath('rows.1.document_id', $return->id)
            ->assertJsonPath('rows.1.debit', 30)
            ->assertJsonPath('summary.debit', 80)
            ->assertJsonPath('summary.credit', 200)
            ->assertJsonPath('summary.balance', 120);
        $this->getJson("/api/reports/supplier-ledger/invoices/{$purchase->id}?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('number', 'PUR-2')
            ->assertJsonPath('contact.name', 'Parts Supplier');
    }

    public function test_supplier_due_excludes_invoices_fully_settled_by_returns(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Parts Supplier', 'type' => 'supplier']);
        $partlyPaid = $this->document($owner, $branch, $supplier, 'purchase', 'PUR-DUE', 200, 50);
        $this->document($owner, $branch, $supplier, 'purchase_return', 'PRT-DUE', 30, 0, $partlyPaid);
        $settled = $this->document($owner, $branch, $supplier, 'purchase', 'PUR-SETTLED', 40, 0);
        $this->document($owner, $branch, $supplier, 'purchase_return', 'PRT-SETTLED', 40, 0, $settled);
        $staff = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Purchases->value],
            'report_permissions' => ['supplier_due'],
        ]);

        $this->actingAs($staff)->getJson("/api/reports?report=supplier_due&branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.invoice', 'PUR-DUE')
            ->assertJsonPath('rows.0.returned', 30)
            ->assertJsonPath('rows.0.paid', 50)
            ->assertJsonPath('rows.0.due', 120)
            ->assertJsonPath('rows.0.status', 'Partial due')
            ->assertJsonPath('summary.invoices', 1)
            ->assertJsonPath('summary.due', 120);
    }

    public function test_supplier_ledger_invoice_rejects_other_branches_tenants_and_users_without_report_access(): void
    {
        $owner = User::factory()->admin()->create();
        $branch = $owner->organization->branches()->firstOrFail();
        $otherBranch = Branch::create(['organization_id' => $owner->organization_id, 'name' => 'Other branch', 'code' => 'OTHER', 'active' => true]);
        $supplier = Contact::create(['organization_id' => $owner->organization_id, 'name' => 'Parts Supplier', 'type' => 'supplier']);
        $purchase = $this->document($owner, $branch, $supplier, 'purchase', 'PUR-SECURE', 100, 0);
        $withoutReport = User::factory()->staff()->for($owner->organization)->create([
            'permissions' => [Permission::Contacts->value],
            'report_permissions' => [],
        ]);
        $otherOwner = User::factory()->admin()->create();

        $this->actingAs($owner)->getJson("/api/reports/supplier-ledger/invoices/{$purchase->id}?branch_id={$otherBranch->id}")->assertNotFound();
        $this->actingAs($withoutReport)->getJson("/api/reports/supplier-ledger/invoices/{$purchase->id}?branch_id={$branch->id}")->assertForbidden();
        $this->actingAs($otherOwner)->getJson("/api/reports/supplier-ledger/invoices/{$purchase->id}")->assertNotFound();
    }

    private function document(User $owner, Branch $branch, Contact $supplier, string $type, string $number, int $total, int $paid, ?Document $purchase = null): Document
    {
        return Document::create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $branch->id,
            'number' => $number,
            'type' => $type,
            'contact_id' => $supplier->id,
            'purchase_id' => $purchase?->id,
            'document_date' => '2026-10-20',
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => $paid,
            'created_by' => $owner->id,
        ]);
    }
}
