<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\StockTransfer;
use App\Models\SupportImpersonation;
use App\Services\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);

        return response()->json(StockTransfer::query()
            ->where('organization_id', $organizationId)
            ->where(fn ($query) => $query->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId))
            ->with('fromBranch', 'toBranch', 'items.product', 'creator:id,name')
            ->latest()->get());
    }

    public function store(Request $request, StockTransferService $service): JsonResponse
    {
        $organizationId = $this->organizationId();
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer'],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id'],
            'transfer_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
        ]);

        return response()->json($service->create($data, $organizationId, Auth::id()), 201);
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
