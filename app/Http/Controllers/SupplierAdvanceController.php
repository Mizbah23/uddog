<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Organization;
use App\Models\PurchasePayment;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceDelivery;
use App\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierAdvanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);

        return response()->json(SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->with('supplier:id,name', 'deliveries.purchase:id,number,total,document_date')
            ->orderByDesc('advance_date')->orderByDesc('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $data = $request->validate([
            'contact_id' => ['required', 'integer', Rule::exists(app(TenantContext::class)->validationTable('contacts'), 'id')->where('organization_id', $organizationId)],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'expected_installments' => ['required', 'integer', 'between:3,6'],
            'advance_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $supplier = Contact::query()->where('organization_id', $organizationId)->findOrFail($data['contact_id']);
        if (! in_array($supplier->type, ['supplier', 'both'], true)) {
            throw ValidationException::withMessages(['contact_id' => 'Select a supplier.']);
        }

        $advance = SupplierAdvance::create([...$data, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'created_by' => Auth::id()]);

        return response()->json($advance->load('supplier:id,name'), 201);
    }

    public function deliver(Request $request, int $advance): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $data = $request->validate([
            'document_id' => ['required', 'integer', Rule::exists(app(TenantContext::class)->validationTable('documents'), 'id')->where('organization_id', $organizationId)->where('branch_id', $branchId)],
            'installment_code' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $delivery = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($advance, $organizationId, $branchId, $data) {
            $record = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($advance);
            $purchase = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($data['document_id']);
            if ($purchase->type !== 'purchase' || $purchase->contact_id !== $record->contact_id || $purchase->payment_type !== 'due'
                || (float) $purchase->amount_paid !== 0.0 || $purchase->purchaseReturns()->exists()
                || SupplierAdvanceDelivery::query()->where('document_id', $purchase->id)->exists()) {
                throw ValidationException::withMessages(['document_id' => 'Choose an unpaid purchase for this supplier that has no returns or previous advance allocation.']);
            }
            if ($purchase->document_date->lt($record->advance_date)) {
                throw ValidationException::withMessages(['document_id' => 'The purchase cannot predate the supplier advance.']);
            }
            $code = trim($data['installment_code']);
            if ($record->deliveries()->count() >= $record->expected_installments) {
                throw ValidationException::withMessages(['installment_code' => 'The planned number of product installments is already complete.']);
            }
            if (SupplierAdvanceDelivery::query()->where('supplier_advance_id', $record->id)->where('installment_code', $code)->exists()) {
                throw ValidationException::withMessages(['installment_code' => 'This installment code is already used for the advance.']);
            }
            $remaining = (int) round((float) $record->amount * 100) - (int) round((float) $record->deliveries()->sum('amount') * 100);
            $purchaseCents = (int) round((float) $purchase->total * 100);
            if ($purchaseCents <= 0 || $purchaseCents > $remaining) {
                throw ValidationException::withMessages(['document_id' => 'The purchase total must be positive and no more than the remaining advance.']);
            }
            $payment = PurchasePayment::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId, 'document_id' => $purchase->id,
                'amount' => $purchase->total, 'payment_method' => 'supplier_advance',
                'payment_date' => $purchase->document_date->toDateString(), 'notes' => 'Supplier advance '.$record->id.' · '.$code,
                'created_by' => Auth::id(),
            ]);
            $purchase->update(['amount_paid' => $purchase->total, 'payment_method' => 'supplier_advance', 'payment_type' => 'full']);

            return SupplierAdvanceDelivery::create([
                'organization_id' => $organizationId, 'branch_id' => $branchId, 'supplier_advance_id' => $record->id,
                'document_id' => $purchase->id, 'purchase_payment_id' => $payment->id, 'installment_code' => $code,
                'amount' => $purchase->total, 'delivery_date' => $purchase->document_date->toDateString(),
                'notes' => $data['notes'] ?? null, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json($delivery->load('purchase:id,number,total,document_date'), 201);
    }

    public function reverseDelivery(Request $request, int $advance, int $delivery): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organizationId, $branchId, $advance, $delivery): void {
            $record = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($advance);
            $linked = SupplierAdvanceDelivery::query()->where('supplier_advance_id', $record->id)->lockForUpdate()->findOrFail($delivery);
            $purchase = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($linked->document_id);
            $payment = PurchasePayment::query()->where('document_id', $purchase->id)->lockForUpdate()->findOrFail($linked->purchase_payment_id);
            if ($purchase->purchaseReturns()->exists() || $purchase->purchasePayments()->count() !== 1
                || (int) round((float) $purchase->amount_paid * 100) !== (int) round((float) $linked->amount * 100)) {
                throw ValidationException::withMessages(['delivery' => 'Resolve related returns and payments before reversing this delivery.']);
            }
            $linked->delete();
            $payment->delete();
            $purchase->update(['amount_paid' => '0.00', 'payment_method' => 'credit', 'payment_type' => 'due']);
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $advance): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organizationId, $branchId, $advance): void {
            $record = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($advance);
            if ($record->deliveries()->exists()) {
                throw ValidationException::withMessages(['advance' => 'Reverse linked deliveries before deleting this advance.']);
            }
            $record->delete();
        });

        return response()->json(['ok' => true]);
    }

    private function scope(Request $request): array
    {
        $organizationId = (int) $request->user()->organization_id;
        abort_unless($organizationId && Organization::query()->findOrFail($organizationId)->setup()['purchases']['allow_supplier_advances'], 403, 'Supplier advances are disabled in System Setup.');
        $branch = app(BranchAccess::class)->activeBranch($request->user(), $organizationId, $request->integer('branch_id') ?: null);

        return [$organizationId, $branch->id];
    }
}
