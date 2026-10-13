<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Document;
use App\Models\GoldJob;
use App\Models\GoldRate;
use App\Models\Product;
use App\Models\SupportImpersonation;
use App\TenantContext;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GoldStoreController extends Controller
{
    public function rates(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $rates = GoldRate::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereDate('effective_on', '<=', today())->orderByDesc('effective_on')->orderBy('purity')->get();

        return response()->json($rates->unique(fn ($rate) => $rate->metal.'|'.strtoupper($rate->purity))->values());
    }

    public function saveRate(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        abort_unless(Auth::user()->hasRole(UserRole::Admin, UserRole::Manager), 403, 'Only an administrator or manager can change metal rates.');
        $data = $request->validate([
            'metal' => ['required', Rule::in(['gold', 'silver', 'platinum'])],
            'purity' => ['required', 'string', 'max:20'],
            'rate_per_gram' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'effective_on' => ['required', 'date'],
        ]);
        $rate = GoldRate::query()->updateOrCreate([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'metal' => $data['metal'],
            'purity' => strtoupper($data['purity']),
            'effective_on' => $data['effective_on'],
        ], ['rate_per_gram' => $data['rate_per_gram'], 'created_by' => Auth::id()]);

        return response()->json($rate, $rate->wasRecentlyCreated ? 201 : 200);
    }

    public function summary(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $products = Product::query()->where('organization_id', $organizationId)->where('is_gold_item', true)
            ->with(['stocks' => fn ($query) => $query->where('branch_id', $branchId)])->orderBy('gold_purity')->orderBy('name')->get();
        $rates = GoldRate::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereDate('effective_on', '<=', today())->orderByDesc('effective_on')->get()->unique(fn ($rate) => $rate->metal.'|'.strtoupper($rate->purity))->keyBy(fn ($rate) => $rate->metal.'|'.strtoupper($rate->purity));
        $inventory = $products->map(function (Product $product) use ($rates) {
            $quantity = (float) ($product->stocks->first()?->quantity_on_hand ?? 0);
            $netWeight = max(0, (float) $product->gold_gross_weight - (float) $product->gold_stone_weight - (float) $product->gold_other_weight);
            $stockWeight = $quantity * $netWeight;
            $rate = $rates->get('gold|'.strtoupper((string) $product->gold_purity));

            return [
                'id' => $product->id, 'name' => $product->name, 'tag_number' => $product->tag_number,
                'purity' => $product->gold_purity, 'quantity' => number_format($quantity, 3, '.', ''),
                'gross_weight' => $product->gold_gross_weight, 'net_weight_each' => number_format($netWeight, 3, '.', ''),
                'net_stock_weight' => number_format($stockWeight, 3, '.', ''),
                'rate_per_gram' => $rate?->rate_per_gram,
                'estimated_metal_value' => number_format($stockWeight * (float) ($rate?->rate_per_gram ?? 0), 2, '.', ''),
                'hallmark_code' => $product->hallmark_code,
            ];
        })->values();
        $jobs = GoldJob::query()->where('organization_id', $organizationId)->where('branch_id', $branchId);
        $exchanges = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->whereIn('type', ['sale', 'resale']);

        return response()->json([
            'inventory' => $inventory,
            'summary' => [
                'gold_items' => $products->count(),
                'net_weight' => number_format($inventory->sum(fn ($row) => (float) $row['net_stock_weight']), 3, '.', ''),
                'estimated_metal_value' => number_format($inventory->sum(fn ($row) => (float) $row['estimated_metal_value']), 2, '.', ''),
                'open_jobs' => (clone $jobs)->whereIn('status', ['open', 'in_progress'])->count(),
                'exchange_weight' => number_format((clone $exchanges)->sum('gold_exchange_weight'), 3, '.', ''),
                'exchange_value' => number_format((clone $exchanges)->sum('gold_exchange_value'), 2, '.', ''),
            ],
            'rates' => $rates->values(),
            'exchanges' => (clone $exchanges)->where('gold_exchange_value', '>', 0)->with('contact:id,name')->latest('document_date')->limit(20)->get(),
            'jobs' => $jobs->with('contact:id,name', 'product:id,name,tag_number', 'creator:id,name')->latest()->limit(30)->get(),
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);

        return response()->json(GoldJob::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->with('contact:id,name', 'product:id,name,tag_number', 'creator:id,name')->latest()->get());
    }

    public function saveJob(Request $request): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $data = $request->validate([
            'job_type' => ['required', Rule::in(['manufacturing', 'repair'])],
            'contact_id' => ['nullable', Rule::exists(app(TenantContext::class)->validationTable('contacts'), 'id')->where('organization_id', $organizationId)],
            'product_id' => ['nullable', Rule::exists(app(TenantContext::class)->validationTable('products'), 'id')->where('organization_id', $organizationId)],
            'document_id' => ['nullable', Rule::exists(app(TenantContext::class)->validationTable('documents'), 'id')->where('organization_id', $organizationId)],
            'gold_purity' => ['nullable', 'string', 'max:20'],
            'input_weight' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'output_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'wastage_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'labor_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $job = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organizationId, $branchId, $data) {
            $job = GoldJob::create([
                ...$data,
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'number' => 'TMP-'.bin2hex(random_bytes(8)),
                'status' => 'open',
                'created_by' => Auth::id(),
            ]);
            $job->update(['number' => 'GJ-'.str_pad((string) $job->id, 6, '0', STR_PAD_LEFT)]);

            return $job;
        });

        return response()->json($job->load('contact:id,name', 'product:id,name,tag_number', 'creator:id,name'), 201);
    }

    public function updateJob(Request $request, int $job): JsonResponse
    {
        [$organizationId, $branchId] = $this->scope($request);
        $saved = GoldJob::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->findOrFail($job);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'completed', 'cancelled'])],
            'output_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'wastage_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'labor_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $saved->update($data);

        return response()->json($saved->fresh()->load('contact:id,name', 'product:id,name,tag_number', 'creator:id,name'));
    }

    /** @return array{int, int} */
    private function scope(Request $request): array
    {
        $user = Auth::user();
        if ($user?->isSuperadmin() && ! SupportImpersonation::activeFor($request)) {
            abort(403, 'Open a company through View as owner before using its workspace.');
        }
        $organization = $user?->organization;
        abort_unless($organization?->gold_store, 404, 'Gold store features are not enabled for this company.');
        $branch = app(BranchAccess::class)->activeBranch($user, $organization->id, $request->integer('branch_id') ?: null);

        return [$organization->id, $branch->id];
    }
}
