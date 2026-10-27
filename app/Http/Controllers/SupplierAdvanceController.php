<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Organization;
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
            ->with('deliveries')->orderByDesc('advance_date')->orderByDesc('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'expected_installments' => ['required', 'integer', 'between:3,6'],
            'advance_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['company_name'] = trim($data['company_name']);
        if ($data['company_name'] === '') {
            throw ValidationException::withMessages(['company_name' => 'Enter a company name.']);
        }

        $advance = SupplierAdvance::create([...$data, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'created_by' => Auth::id()]);

        return response()->json($advance->load('deliveries'), 201);
    }

    public function deliver(Request $request, int $advance): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $data = $request->validate([
            'installment_code' => ['required', 'string', 'max:100'],
            'product_details' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'delivery_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $delivery = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($advance, $organizationId, $branchId, $data) {
            $record = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($advance);
            $code = trim($data['installment_code']);
            $productDetails = trim($data['product_details']);
            if ($code === '' || $productDetails === '') {
                throw ValidationException::withMessages(['installment_code' => 'Enter an installment code and product details.']);
            }
            if ($data['delivery_date'] < $record->advance_date->toDateString()) {
                throw ValidationException::withMessages(['delivery_date' => 'Delivery cannot predate the advance.']);
            }
            if ($record->deliveries()->count() >= $record->expected_installments) {
                throw ValidationException::withMessages(['installment_code' => 'The planned number of product installments is already complete.']);
            }
            if ($record->deliveries()->where('installment_code', $code)->exists()) {
                throw ValidationException::withMessages(['installment_code' => 'This installment code is already used for the advance.']);
            }
            $remaining = (int) round((float) $record->amount * 100) - (int) round((float) $record->deliveries()->sum('amount') * 100);
            $deliveryCents = (int) round((float) $data['amount'] * 100);
            if ($deliveryCents > $remaining) {
                throw ValidationException::withMessages(['amount' => 'The delivery value cannot exceed the remaining advance.']);
            }

            return SupplierAdvanceDelivery::create([
                ...$data, 'installment_code' => $code, 'product_details' => $productDetails,
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'supplier_advance_id' => $record->id, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json($delivery, 201);
    }

    public function reverseDelivery(Request $request, int $advance, int $delivery): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organizationId, $branchId, $advance, $delivery): void {
            $record = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($advance);
            SupplierAdvanceDelivery::query()->where('supplier_advance_id', $record->id)->where('organization_id', $organizationId)
                ->where('branch_id', $branchId)->lockForUpdate()->findOrFail($delivery)->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $advance): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organizationId, $branchId, $advance): void {
            $record = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($advance);
            if ($record->deliveries()->exists()) {
                throw ValidationException::withMessages(['advance' => 'Remove its installment records before deleting this advance.']);
            }
            $record->delete();
        });

        return response()->json(['ok' => true]);
    }

    private function scope(Request $request): array
    {
        $organizationId = (int) $request->user()->organization_id;
        abort_unless($organizationId && Organization::query()->findOrFail($organizationId)->setup()['supplier_advances']['enabled'], 403, 'Supplier advances are disabled in System Setup.');
        $branch = app(BranchAccess::class)->activeBranch($request->user(), $organizationId, $request->integer('branch_id') ?: null);

        return [$organizationId, $branch->id];
    }
}
