<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Organization;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceDelivery;
use App\Models\SupplierTtEntry;
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

        return response()->json([
            'advances' => SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->orderByDesc('advance_date')->orderByDesc('id')->get(),
            'deliveries' => SupplierAdvanceDelivery::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->orderByDesc('delivery_date')->orderByDesc('id')->get(),
            'entries' => SupplierTtEntry::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->orderByDesc('entry_date')->orderByDesc('id')->get(),
        ]);
    }

    public function storeEntry(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'entry_type' => ['required', Rule::in(['opening_paid', 'opening_received', 'incentive', 'commission', 'credit', 'received_adjustment'])],
            'particular' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['company_name'] = trim($data['company_name']);
        if ($data['company_name'] === '') {
            throw ValidationException::withMessages(['company_name' => 'Enter a company name.']);
        }
        $existingName = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($data['company_name'])])->value('company_name');
        $existingName ??= SupplierTtEntry::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($data['company_name'])])->value('company_name');
        $data['company_name'] = $existingName ?: $data['company_name'];

        return response()->json(SupplierTtEntry::create([...$data, 'organization_id' => $organizationId,
            'branch_id' => $branchId, 'created_by' => Auth::id()]), 201);
    }

    public function destroyEntry(Request $request, int $entry): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        SupplierTtEntry::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->findOrFail($entry)->delete();

        return response()->json(['ok' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'advance_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['company_name'] = trim($data['company_name']);
        if ($data['company_name'] === '') {
            throw ValidationException::withMessages(['company_name' => 'Enter a company name.']);
        }

        $existingName = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($data['company_name'])])->value('company_name');
        $existingName ??= SupplierTtEntry::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($data['company_name'])])->value('company_name');
        $data['company_name'] = $existingName ?: $data['company_name'];
        $advance = SupplierAdvance::create([...$data, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'created_by' => Auth::id()]);

        return response()->json($advance, 201);
    }

    public function deliver(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'installment_code' => ['required', 'string', 'max:100'],
            'product_details' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'delivery_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $delivery = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organizationId, $branchId, $data) {
            $companyName = trim($data['company_name']);
            $code = trim($data['installment_code']);
            $productDetails = trim($data['product_details']);
            if ($companyName === '' || $code === '' || $productDetails === '') {
                throw ValidationException::withMessages(['company_name' => 'Enter a company name, installment code and product details.']);
            }
            $companyAdvances = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($companyName)])->lockForUpdate()->get();
            $companyEntries = SupplierTtEntry::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($companyName)])->lockForUpdate()->get();
            if ($companyAdvances->isEmpty() && $companyEntries->whereIn('entry_type', ['opening_paid', 'incentive', 'commission', 'credit'])->isEmpty()) {
                throw ValidationException::withMessages(['company_name' => 'Send an advance to this company before recording a product installment.']);
            }
            $companyName = $companyAdvances->first()?->company_name ?? $companyEntries->first()->company_name;
            $companyDeliveries = SupplierAdvanceDelivery::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($companyName)])->lockForUpdate()->get();
            if ($companyDeliveries->contains(fn ($part) => mb_strtolower($part->installment_code) === mb_strtolower($code))) {
                throw ValidationException::withMessages(['installment_code' => 'This installment code is already used for the company.']);
            }
            $sentCents = $companyAdvances->filter(fn ($advance) => $advance->advance_date->toDateString() <= $data['delivery_date'])
                ->sum(fn ($advance) => (int) round((float) $advance->amount * 100));
            $sentCents += $companyEntries->whereIn('entry_type', ['opening_paid', 'incentive', 'commission', 'credit'])
                ->filter(fn ($entry) => $entry->entry_date->toDateString() <= $data['delivery_date'])
                ->sum(fn ($entry) => (int) round((float) $entry->amount * 100));
            $receivedCents = $companyDeliveries->filter(fn ($part) => $part->delivery_date->toDateString() <= $data['delivery_date'])
                ->sum(fn ($part) => (int) round((float) $part->amount * 100));
            $receivedCents += $companyEntries->whereIn('entry_type', ['opening_received', 'received_adjustment'])
                ->filter(fn ($entry) => $entry->entry_date->toDateString() <= $data['delivery_date'])
                ->sum(fn ($entry) => (int) round((float) $entry->amount * 100));
            $remaining = $sentCents - $receivedCents;
            $deliveryCents = (int) round((float) $data['amount'] * 100);
            $overallRemaining = $companyAdvances->sum(fn ($advance) => (int) round((float) $advance->amount * 100))
                + $companyEntries->whereIn('entry_type', ['opening_paid', 'incentive', 'commission', 'credit'])->sum(fn ($entry) => (int) round((float) $entry->amount * 100))
                - $companyDeliveries->sum(fn ($part) => (int) round((float) $part->amount * 100))
                - $companyEntries->whereIn('entry_type', ['opening_received', 'received_adjustment'])->sum(fn ($entry) => (int) round((float) $entry->amount * 100));
            if ($deliveryCents > $remaining || $deliveryCents > $overallRemaining
                || ! $this->balanceNeverNegative($companyAdvances, $companyDeliveries, $companyEntries, [
                    'date' => $data['delivery_date'], 'amount' => $deliveryCents,
                ])) {
                throw ValidationException::withMessages(['amount' => 'The delivery value cannot exceed this company’s available advance balance on the delivery date.']);
            }

            return SupplierAdvanceDelivery::create([
                ...$data, 'company_name' => $companyName, 'installment_code' => $code, 'product_details' => $productDetails,
                'organization_id' => $organizationId, 'branch_id' => $branchId,
                'created_by' => Auth::id(),
            ]);
        });

        return response()->json($delivery, 201);
    }

    public function reverseDelivery(Request $request, int $delivery): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organizationId, $branchId, $delivery): void {
            SupplierAdvanceDelivery::query()->where('organization_id', $organizationId)
                ->where('branch_id', $branchId)->lockForUpdate()->findOrFail($delivery)->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $advance): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organizationId, $branchId, $advance): void {
            $record = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->lockForUpdate()->findOrFail($advance);
            $companyAdvances = SupplierAdvance::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($record->company_name)])->lockForUpdate()->get();
            if ($record->deliveries()->exists()) {
                throw ValidationException::withMessages(['advance' => 'Remove product installments linked to this advance before deleting it.']);
            }
            $sent = $companyAdvances->sum(fn ($item) => (int) round((float) $item->amount * 100));
            $companyDeliveries = SupplierAdvanceDelivery::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->whereRaw('LOWER(company_name) = ?', [mb_strtolower($record->company_name)])->get();
            $received = $companyDeliveries->sum(fn ($item) => (int) round((float) $item->amount * 100));
            if ($sent - (int) round((float) $record->amount * 100) < $received
                || ! $this->balanceNeverNegative($companyAdvances->reject(fn ($item) => $item->id === $record->id), $companyDeliveries)) {
                throw ValidationException::withMessages(['advance' => 'Remove product installments before deleting this advance; the company balance would become negative.']);
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

    private function balanceNeverNegative($advances, $deliveries, $entries = null, ?array $newDelivery = null): bool
    {
        $events = [];
        foreach ($advances as $advance) {
            $events[] = [$advance->advance_date->toDateString(), 0, (int) round((float) $advance->amount * 100)];
        }
        foreach ($deliveries as $delivery) {
            $events[] = [$delivery->delivery_date->toDateString(), 1, -((int) round((float) $delivery->amount * 100))];
        }
        foreach ($entries ?? [] as $entry) {
            $paid = in_array($entry->entry_type, ['opening_paid', 'incentive', 'commission', 'credit'], true);
            $events[] = [$entry->entry_date->toDateString(), $paid ? 0 : 1, ($paid ? 1 : -1) * ((int) round((float) $entry->amount * 100))];
        }
        if ($newDelivery) {
            $events[] = [$newDelivery['date'], 1, -$newDelivery['amount']];
        }
        usort($events, fn ($left, $right) => [$left[0], $left[1]] <=> [$right[0], $right[1]]);
        $balance = 0;
        foreach ($events as $event) {
            $balance += $event[2];
            if ($balance < 0) {
                return false;
            }
        }

        return true;
    }
}
