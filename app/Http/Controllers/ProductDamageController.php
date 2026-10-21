<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductDamage;
use App\Models\StockMovement;
use App\Models\SupportImpersonation;
use App\Services\ProductDamageService;
use App\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductDamageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        $data = $request->validate(['product_id' => ['nullable', 'integer']]);

        return response()->json(ProductDamage::query()
            ->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->when($data['product_id'] ?? null, fn ($query, $productId) => $query->where('product_id', $productId))
            ->with('purchase:id,number,document_date', 'product:id,name,sku,unit', 'creator:id,name')
            ->orderByDesc('id')->paginate(50));
    }

    public function purchases(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim($data['search'] ?? '');
        $purchases = Document::query()
            ->where('organization_id', $organizationId)->where('branch_id', $branchId)->where('type', 'purchase')
            ->with('contact:id,name', 'items.product:id,name,sku,unit')
            ->when($search, fn ($query) => $query->where(fn ($matches) => $matches
                ->where('number', 'like', "%{$search}%")
                ->orWhereHas('contact', fn ($contact) => $contact->where('name', 'like', "%{$search}%"))))
            ->orderByDesc('id')->limit(100)->get();
        $purchaseIds = $purchases->pluck('id')->all();
        $returned = DB::connection(app(TenantContext::class)->connectionName())->table('document_items')
            ->join('documents', 'documents.id', '=', 'document_items.document_id')
            ->where('documents.type', 'purchase_return')->whereIn('documents.purchase_id', $purchaseIds)
            ->selectRaw('documents.purchase_id, document_items.product_id, SUM(document_items.quantity) AS quantity')
            ->groupBy('documents.purchase_id', 'document_items.product_id')->get()
            ->keyBy(fn ($row) => $row->purchase_id.':'.$row->product_id);
        $damaged = ProductDamage::query()->whereIn('purchase_id', $purchaseIds)
            ->selectRaw('purchase_id, product_id, SUM(quantity) AS quantity')
            ->groupBy('purchase_id', 'product_id')->get()
            ->keyBy(fn ($row) => $row->purchase_id.':'.$row->product_id);

        return response()->json($purchases->map(function (Document $purchase) use ($returned, $damaged): array {
            $items = $purchase->items->groupBy('product_id')->map(function ($lines, $productId) use ($purchase, $returned, $damaged): array {
                $key = $purchase->id.':'.$productId;
                $purchased = (float) $lines->sum('quantity');
                $remaining = max(0, $purchased - (float) ($returned->get($key)?->quantity ?? 0) - (float) ($damaged->get($key)?->quantity ?? 0));

                return [
                    'product' => $lines->first()->product,
                    'purchased_quantity' => number_format($purchased, 3, '.', ''),
                    'remaining_quantity' => number_format($remaining, 3, '.', ''),
                ];
            })->values()->all();

            return [
                'id' => $purchase->id,
                'number' => $purchase->number,
                'document_date' => $purchase->document_date->toDateString(),
                'supplier' => $purchase->contact?->name,
                'items' => $items,
            ];
        }));
    }

    public function timeline(Request $request, int $product): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        Product::query()->where('organization_id', $organizationId)->findOrFail($product);

        return response()->json(StockMovement::query()
            ->where('organization_id', $organizationId)->where('branch_id', $branchId)->where('product_id', $product)
            ->with('document:id,number,type,document_date', 'productDamage.purchase:id,number,document_date', 'productDamage.creator:id,name', 'stockCheck:id,number', 'stockTransfer:id,number')
            ->orderByDesc('id')->paginate(50));
    }

    public function store(Request $request, ProductDamageService $service): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        $data = $request->validate([
            'purchase_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
            'damaged_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($service->create($data, $organizationId, $branchId, Auth::id()), 201);
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
        return app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, $request->integer('branch_id') ?: null)->id;
    }
}
