<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockCheck;
use App\Models\SupportImpersonation;
use App\Services\StockCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCheckController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);

        return response()->json(StockCheck::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->with('branch', 'items.product', 'creator:id,name', 'completer:id,name')
            ->latest()->get());
    }

    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        $data = $request->validate([
            'check_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (StockCheck::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['status' => 'Complete the existing draft stock check for this branch first.']);
        }

        $products = Product::query()->where('organization_id', $organizationId)->where('active', true)->orderBy('name')->get();
        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one active product before starting a stock check.']);
        }
        $stocks = ProductStock::query()->where('branch_id', $branchId)->whereIn('product_id', $products->pluck('id'))->pluck('quantity_on_hand', 'product_id');

        $stockCheck = DB::transaction(function () use ($data, $organizationId, $branchId, $products, $stocks) {
            $stockCheck = StockCheck::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'number' => 'TMP-'.bin2hex(random_bytes(12)),
                'check_date' => $data['check_date'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $stockCheck->update(['number' => 'CHK-'.str_pad((string) $stockCheck->id, 6, '0', STR_PAD_LEFT)]);
            foreach ($products as $product) {
                $stockCheck->items()->create([
                    'product_id' => $product->id,
                    'expected_quantity' => $stocks[$product->id] ?? 0,
                ]);
            }

            return $stockCheck;
        });

        return response()->json($stockCheck->load('branch', 'items.product', 'creator:id,name', 'completer:id,name'), 201);
    }

    public function update(Request $request, int $stockCheck): JsonResponse
    {
        $organizationId = $this->organizationId();
        $saved = StockCheck::query()->where('organization_id', $organizationId)->findOrFail($stockCheck);
        if ($saved->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Completed stock checks cannot be edited.']);
        }
        $data = $request->validate([
            'check_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.counted_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
        ]);
        $existingItems = $saved->items()->get()->keyBy('id');
        if (count($data['items']) !== $existingItems->count() || collect($data['items'])->contains(fn ($item) => ! $existingItems->has($item['id']))) {
            throw ValidationException::withMessages(['items' => 'The stock check items do not match this count session.']);
        }
        DB::transaction(function () use ($saved, $data, $existingItems) {
            $saved->update(['check_date' => $data['check_date'], 'notes' => $data['notes'] ?? null]);
            foreach ($data['items'] as $item) {
                $existingItems[$item['id']]->update(['counted_quantity' => $item['counted_quantity']]);
            }
        });

        return response()->json($saved->fresh()->load('branch', 'items.product', 'creator:id,name', 'completer:id,name'));
    }

    public function complete(Request $request, int $stockCheck, StockCheckService $service): JsonResponse
    {
        $organizationId = $this->organizationId();
        $saved = StockCheck::query()->where('organization_id', $organizationId)->where('branch_id', $this->branchId($request, $organizationId))->findOrFail($stockCheck);

        return response()->json($service->complete($saved, Auth::id()));
    }

    private function organizationId(): int
    {
        if (Auth::user()?->isSuperadmin() && ! SupportImpersonation::activeFor(request())) {
            abort(403, 'Open a company through View as owner before using its workspace.');
        }
        abort_unless(Auth::user()?->organization_id, 403, 'Select a client account before using the inventory workspace.');

        return Auth::user()->organization_id;
    }

    private function branchId(Request $request, int $organizationId): int
    {
        $branch = Branch::query()->where('organization_id', $organizationId)
            ->when($request->input('branch_id'), fn ($query, $branchId) => $query->whereKey($branchId), fn ($query) => $query->where('is_default', true))
            ->where('active', true)->first();
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Select an active branch.']);
        }

        return $branch->id;
    }
}
