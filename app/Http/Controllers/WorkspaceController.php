<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\GoldJob;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductDamage;
use App\Models\ProductStock;
use App\Models\PurchaseInstallment;
use App\Models\PurchasePayment;
use App\Models\SaleInstallment;
use App\Models\StockCheckItem;
use App\Models\StockMovement;
use App\Models\StockTransferItem;
use App\Models\SupportImpersonation;
use App\Models\User;
use App\Permission;
use App\ReportType;
use App\Services\CashBalanceService;
use App\Services\DocumentDeletionService;
use App\Services\InventoryService;
use App\SubscriptionStatus;
use App\TenantContext;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function session(): JsonResponse
    {
        $impersonation = SupportImpersonation::activeFor(request());
        $user = Auth::user()?->load('organization');
        if ($user && (! $user->active || $user->access_paused)) {
            Auth::logout();

            return response()->json([
                'user' => null,
                'setup_required' => false,
                'account_disabled' => true,
            ]);
        }
        $organization = $user?->organization;
        $expiryDate = $organization?->subscription_ends_at;
        $abilities = $user ? collect(Permission::cases())
            ->filter(fn (Permission $permission) => $user->hasPermission($permission))
            ->map(fn (Permission $permission) => $permission->value)
            ->values()
            ->all() : [];

        return response()->json([
            'user' => $user,
            'gold_store' => (bool) ($organization?->gold_store ?? false),
            'shared_stock_sales' => (bool) ($organization?->shared_stock_sales ?? false),
            'setup_required' => ! User::query()->exists(),
            'permissions' => $user ? [
                'manage_clients' => $user->isSuperadmin(),
                'manage_users' => $user->hasRole(UserRole::Superadmin, UserRole::Admin),
                'manage_settings' => $user->hasRole(UserRole::Admin),
                'manage_system_setup' => $user->hasRole(UserRole::Admin)
                    && (! $organization?->isDemo() || (bool) $impersonation),
                'use_workspace' => (bool) ($impersonation?->impersonator->isSuperadmin())
                    || (! $user->isSuperadmin() && $user->organization?->canUseWorkspace() === true),
                'abilities' => $abilities,
                'reports' => collect(ReportType::cases())->filter(fn (ReportType $report) => $user->hasReportAccess($report))
                    ->map(fn (ReportType $report) => $report->value)->values()->all(),
            ] : null,
            'impersonation' => $impersonation ? [
                'id' => $impersonation->id,
                'impersonator' => $impersonation->impersonator->only(['id', 'name', 'role']),
                'owner' => $user->only(['id', 'name']),
                'organization' => $impersonation->organization->only(['id', 'name']),
                'started_at' => $impersonation->created_at,
            ] : null,
            'subscription' => $user?->hasRole(UserRole::Admin) ? [
                'plan_name' => $organization?->plan_name,
                'status' => $organization?->subscription_status?->value,
                'ends_on' => $expiryDate?->toDateString(),
                'days_remaining' => $expiryDate ? (int) today()->diffInDays($expiryDate, false) : null,
                'active' => $organization?->canUseWorkspace() ?? false,
                'access_paused' => $organization?->access_paused ?? false,
            ] : null,
            'operational_setup' => $user?->organization ? collect($user->organization->setup())->only(['sales', 'purchases', 'supplier_advances', 'store'])->all() : null,
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        $user = DB::transaction(function () use ($data) {
            if (User::query()->exists()) {
                throw ValidationException::withMessages(['email' => 'Setup has already been completed.']);
            }

            $organization = Organization::create([
                'name' => 'Primary Client',
                'plan_name' => 'Owner',
                'subscription_status' => SubscriptionStatus::Active,
            ]);

            return User::create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => UserRole::Superadmin,
                'active' => true,
            ]);
        });
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->load('organization')], 201);
    }

    public function login(Request $request): JsonResponse
    {
        SupportImpersonation::endFor($request);
        $credentials = $request->validate(['email' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);
        $identifier = trim($credentials['email']);

        if (str_contains($identifier, '@')) {
            $request->validate(['email' => ['email']]);
            $authenticated = Auth::attempt([
                'email' => $identifier,
                'password' => $credentials['password'],
                'active' => true,
                'access_paused' => false,
            ]);
        } else {
            $matchingUsers = User::query()
                ->where('mobile', $identifier)
                ->where('role', '!=', UserRole::Superadmin->value)
                ->limit(2)
                ->get(['id', 'role']);
            $phoneUser = $matchingUsers->count() === 1 ? $matchingUsers->first() : null;
            $authenticated = $phoneUser && Auth::attempt([
                'id' => $phoneUser->id,
                'role' => $phoneUser->role->value,
                'password' => $credentials['password'],
                'active' => true,
                'access_paused' => false,
            ]);
        }

        if (! $authenticated) {
            throw ValidationException::withMessages(['email' => 'The email or phone number or password is incorrect.']);
        }
        if (config('tenancy.enabled')) {
            $tenant = app(TenantContext::class)->organization();
            $user = Auth::user();
            if (($tenant && ($user->isSuperadmin() || $user->organization_id !== $tenant->id))
                || (! $tenant && ! $user->isSuperadmin())) {
                Auth::logout();
                throw ValidationException::withMessages(['email' => 'The email or phone number or password is incorrect.']);
            }
        }
        $request->session()->regenerate();

        return response()->json(['user' => Auth::user()->load('organization')]);
    }

    public function logout(Request $request): JsonResponse
    {
        SupportImpersonation::endFor($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function overview(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        $products = $this->productsForBranch($organizationId, $branchId)->where('active', true);
        $user = Auth::user();
        $cash = $user->organization?->setup()['store']['allow_cash_handover']
            ? app(CashBalanceService::class)->overview($organizationId, $branchId, $user) : null;
        $canViewSales = $user->hasPermission(Permission::Sales) || $user->hasPermission(Permission::Resales) || $user->hasPermission(Permission::SalesReturns);
        $canViewPurchases = $user->hasPermission(Permission::Purchases) || $user->hasPermission(Permission::PurchaseReturns);
        $visibleSaleTypes = $canViewSales ? ['sale', 'resale', 'sale_return'] : [];
        $visiblePurchaseTypes = $canViewPurchases ? ['purchase', 'purchase_return'] : [];
        $visibleTypes = [...$visibleSaleTypes, ...$visiblePurchaseTypes];
        $documents = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId);
        $yearDocuments = (clone $documents)->whereIn('type', $visibleTypes)->whereYear('document_date', now()->year)->with(['items.product:id,name,unit'])->get();
        $monthlyTotals = collect(range(1, 12))->mapWithKeys(fn (int $month) => [$month => ['month' => now()->setDate(now()->year, $month, 1)->format('M'), 'sales' => 0, 'purchases' => 0]]);
        $productSales = [];
        foreach ($yearDocuments as $document) {
            $month = (int) $document->document_date->format('n');
            $monthTotal = $monthlyTotals->get($month);
            if (in_array($document->type, ['sale', 'resale', 'sale_return'], true)) {
                $sign = $document->type === 'sale_return' ? -1 : 1;
                $monthTotal['sales'] += $sign * (float) $document->total;
                foreach ($document->items as $item) {
                    $product = $item->product;
                    if (! $product) {
                        continue;
                    }
                    $productSales[$product->id] ??= ['id' => $product->id, 'name' => $product->name, 'unit' => $product->unit, 'quantity_mills' => 0];
                    $productSales[$product->id]['quantity_mills'] += $sign * (int) round((float) $item->quantity * 1000);
                }
            } elseif (in_array($document->type, ['purchase', 'purchase_return'], true)) {
                $monthTotal['purchases'] += ($document->type === 'purchase_return' ? -1 : 1) * (float) $document->total;
            }
            $monthlyTotals->put($month, $monthTotal);
        }
        $rankedProducts = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'unit' => $product->unit,
            'quantity' => max(0, ($productSales[$product->id]['quantity_mills'] ?? 0) / 1000),
        ]);
        $highestSellingProducts = $rankedProducts
            ->filter(fn (array $product) => $product['quantity'] > 0)
            ->sort(fn (array $first, array $second) => ($second['quantity'] <=> $first['quantity']) ?: strcmp($first['name'], $second['name']))
            ->take(3)->values();
        $highestSellingIds = $highestSellingProducts->pluck('id')->all();
        $lowestSellingProducts = $rankedProducts
            ->reject(fn (array $product) => in_array($product['id'], $highestSellingIds, true))
            ->sort(fn (array $first, array $second) => ($first['quantity'] <=> $second['quantity']) ?: strcmp($first['name'], $second['name']))
            ->take(3)->values();
        $lowStockProducts = $products->filter(fn (Product $product) => ! $product->stock_source_product_id && (float) $product->quantity_on_hand <= (float) $product->reorder_level)->sortBy('quantity_on_hand')->take(6)->values();

        if (! $user->hasRole(UserRole::Admin)) {
            return response()->json([
                'cash' => $cash,
                'products' => $products->count(),
                'low_stock' => $products->filter(fn (Product $product) => ! $product->stock_source_product_id && (float) $product->quantity_on_hand <= (float) $product->reorder_level)->count(),
                'recent_documents' => (clone $documents)
                    ->when($visibleTypes, fn ($query) => $query->whereIn('type', $visibleTypes), fn ($query) => $query->whereRaw('1 = 0'))
                    ->with('contact:id,name')
                    ->latest()
                    ->limit(6)
                    ->get(['id', 'contact_id', 'number', 'type', 'document_date', 'created_at'])
                    ->map(fn (Document $document) => [
                        'id' => $document->id,
                        'number' => $document->number,
                        'type' => $document->type,
                        'document_date' => $document->document_date,
                        'contact' => $document->contact?->only(['name']),
                    ]),
                'low_stock_products' => $lowStockProducts->map(fn (Product $product) => $product->only(['id', 'name', 'sku', 'unit', 'quantity_on_hand'])),
                'highest_selling_products' => $highestSellingProducts,
                'lowest_selling_products' => $lowestSellingProducts,
            ]);
        }

        $collectionAlerts = $canViewSales
            ? SaleInstallment::query()
                ->where('organization_id', $organizationId)
                ->where('branch_id', $branchId)
                ->whereHas('document', fn ($query) => $query->whereIn('type', array_values(array_filter([
                    $user->hasPermission(Permission::Sales) ? 'sale' : null,
                    $user->hasPermission(Permission::Resales) ? 'resale' : null,
                ]))))
                ->where('due_date', '<=', today()->addDays(7))
                ->whereRaw('amount_paid < amount')
                ->with('document.contact:id,name')
                ->orderBy('due_date')
                ->limit(100)
                ->get()
                ->map(function (SaleInstallment $installment) {
                    $daysUntilDue = (int) today()->diffInDays($installment->due_date, false);

                    return [
                        'id' => $installment->id,
                        'due_date' => $installment->due_date->toDateString(),
                        'amount_due' => $installment->balance_due,
                        'days_until_due' => $daysUntilDue,
                        'document' => [
                            'id' => $installment->document->id,
                            'number' => $installment->document->number,
                            'type' => $installment->document->type,
                            'contact' => $installment->document->contact?->only(['name']),
                        ],
                    ];
                })
            : collect();

        return response()->json([
            'cash' => $cash,
            'products' => $products->count(),
            'stock_value' => $products->sum(fn (Product $product) => (float) $product->quantity_on_hand * (float) $product->cost_price),
            'low_stock' => $products->filter(fn (Product $product) => ! $product->stock_source_product_id && (float) $product->quantity_on_hand <= (float) $product->reorder_level)->count(),
            'sales_total' => $canViewSales ? (clone $documents)->whereIn('type', ['sale', 'resale'])->sum('total') - (clone $documents)->where('type', 'sale_return')->sum('total') : 0,
            'purchase_total' => $canViewPurchases ? (clone $documents)->where('type', 'purchase')->sum('total') : 0,
            'recent_documents' => (clone $documents)->when($visibleTypes, fn ($query) => $query->whereIn('type', $visibleTypes), fn ($query) => $query->whereRaw('1 = 0'))->with('contact')->latest()->limit(6)->get(),
            'low_stock_products' => $lowStockProducts,
            'monthly_totals' => $monthlyTotals->values(),
            'highest_selling_products' => $highestSellingProducts,
            'lowest_selling_products' => $lowestSellingProducts,
            'collection_alerts' => $collectionAlerts,
            'supplier_due_list' => $canViewPurchases ? Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->where('type', 'purchase')->whereRaw('amount_paid < total')->with('contact:id,name', 'purchaseReturns:id,purchase_id,total')->get()->map(fn (Document $purchase) => ['id' => $purchase->id, 'number' => $purchase->number, 'date' => $purchase->document_date, 'supplier' => $purchase->contact?->name, 'amount_due' => number_format(max(0, (float) $purchase->total - (float) $purchase->amount_paid - (float) $purchase->purchaseReturns->sum('total')), 2, '.', '')])->filter(fn (array $purchase) => (float) $purchase['amount_due'] > 0)->sortByDesc('amount_due')->take(8)->values() : collect(),
            'customer_due_list' => $canViewSales ? Document::query()
                ->where('organization_id', $organizationId)
                ->where('branch_id', $branchId)
                ->whereIn('type', array_values(array_filter([
                    $user->hasPermission(Permission::Sales) ? 'sale' : null,
                    $user->hasPermission(Permission::Resales) ? 'resale' : null,
                ])))
                ->whereRaw('total > amount_paid')
                ->selectRaw('contact_id, COUNT(*) as invoices_count, SUM(total - amount_paid) as amount_due, MIN(document_date) as oldest_due_date')
                ->with('contact:id,name,phone')
                ->groupBy('contact_id')
                ->orderByDesc('amount_due')
                ->limit(8)
                ->get()
                ->map(fn (Document $due) => [
                    'contact_id' => $due->contact_id,
                    'customer' => $due->contact?->name ?? 'Walk-in customer',
                    'phone' => $due->contact?->phone,
                    'invoices_count' => (int) $due->invoices_count,
                    'amount_due' => number_format((float) $due->amount_due, 2, '.', ''),
                    'oldest_due_date' => $due->oldest_due_date,
                ]) : collect(),
            'overdue_customers' => $canViewSales ? Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->whereIn('type', array_values(array_filter([$user->hasPermission(Permission::Sales) ? 'sale' : null, $user->hasPermission(Permission::Resales) ? 'resale' : null])))->whereRaw('(total - amount_paid) > 0')->whereDate('document_date', '<=', today()->subDays(30))->with('contact:id,name,phone')->orderBy('document_date')->limit(8)->get()->map(fn (Document $sale) => ['id' => $sale->id, 'number' => $sale->number, 'date' => $sale->document_date, 'customer' => $sale->contact?->name, 'phone' => $sale->contact?->phone, 'amount_due' => $sale->balance_due, 'days_outstanding' => $sale->document_date->diffInDays(today())]) : collect(),
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);

        return response()->json($this->productsForBranch($organizationId, $branchId, $request->query('search')));
    }

    public function saveProduct(Request $request, ?int $product = null): JsonResponse
    {
        $organizationId = $this->organizationId();
        $savedProduct = $product ? Product::query()->where('organization_id', $organizationId)->findOrFail($product) : null;
        if (! $savedProduct && ! $request->exists('reorder_level')) {
            $request->merge(['reorder_level' => Auth::user()->organization()->firstOrFail()->setup()['store']['default_reorder_level']]);
        }
        if (! $savedProduct && ! $request->exists('unit')) {
            $request->merge(['unit' => 'pc']);
        }
        if ($request->exists('sku')) {
            $sku = trim((string) $request->input('sku'));
            $request->merge(['sku' => $sku === '' ? null : $sku]);
        }
        if ($request->exists('barcode')) {
            $barcode = trim((string) $request->input('barcode'));
            $request->merge(['barcode' => $barcode === '' ? null : $barcode]);
        }

        $allowedUnits = Auth::user()->organization()->firstOrFail()->setup()['store']['units'];
        $allowedUnits[] = 'pc';
        if ($savedProduct) {
            $allowedUnits[] = $savedProduct->unit;
        }
        $data = $request->validate([
            'sku' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique(app(TenantContext::class)->validationTable('products'))->where('organization_id', $organizationId)->ignore($savedProduct?->id)],
            'barcode' => [
                'bail',
                'sometimes',
                'nullable',
                'regex:/^\d{13}$/',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $digits = array_map('intval', str_split((string) $value));
                    $sum = 0;
                    for ($index = 0; $index < 12; $index++) {
                        $sum += $digits[$index] * ($index % 2 === 0 ? 1 : 3);
                    }
                    if ((10 - ($sum % 10)) % 10 !== $digits[12]) {
                        $fail('The barcode must contain a valid EAN-13 check digit.');
                    }
                },
                Rule::unique(app(TenantContext::class)->validationTable('products'))->where('organization_id', $organizationId)->ignore($savedProduct?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists(app(TenantContext::class)->validationTable('categories'), 'id')->where('organization_id', $organizationId)],
            'unit' => ['required', Rule::in(array_values(array_unique($allowedUnits)))],
            'cost_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'sale_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'is_gold_item' => ['sometimes', 'boolean'],
            'gold_purity' => ['required_if:is_gold_item,1', 'nullable', 'string', 'max:20'],
            'gold_gross_weight' => ['required_if:is_gold_item,1', 'nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'gold_stone_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'gold_other_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'stone_value' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'making_charge_mode' => ['nullable', Rule::in(['per_gram', 'percentage', 'flat'])],
            'making_charge' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,3'],
            'tag_number' => ['nullable', 'string', 'max:80', Rule::unique(app(TenantContext::class)->validationTable('products'))->where('organization_id', $organizationId)->ignore($savedProduct?->id)],
            'hallmark_code' => ['nullable', 'string', 'max:80'],
            'active' => ['boolean'],
            'stock_source_product_id' => ['nullable', Rule::exists(app(TenantContext::class)->validationTable('products'), 'id')->where('organization_id', $organizationId)],
            'stock_source_quantity' => ['required_with:stock_source_product_id', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);
        $sourceId = $data['stock_source_product_id'] ?? null;
        $sharedStockEnabled = (bool) Auth::user()->organization()->firstOrFail()->shared_stock_sales;
        if ($sourceId && ! $sharedStockEnabled && (! $savedProduct || (int) $savedProduct->stock_source_product_id !== (int) $sourceId || (float) $savedProduct->stock_source_quantity !== (float) ($data['stock_source_quantity'] ?? 1))) {
            throw ValidationException::withMessages(['stock_source_product_id' => 'Shared stock sales are not enabled for this company.']);
        }
        if ($sourceId) {
            $source = Product::query()->where('organization_id', $organizationId)->findOrFail($sourceId);
            if (($savedProduct && $savedProduct->id === $source->id) || $source->stock_source_product_id || $source->is_gold_item || ($data['is_gold_item'] ?? false)) {
                throw ValidationException::withMessages(['stock_source_product_id' => 'Choose a non-gold stock product that is not itself linked to another product.']);
            }
            if ($savedProduct && ((float) $savedProduct->quantity_on_hand !== 0.0 || $savedProduct->stocks()->where('quantity_on_hand', '!=', 0)->exists() || (! $savedProduct->stock_source_product_id && (DocumentItem::query()->where('product_id', $savedProduct->id)->exists() || StockMovement::query()->where('product_id', $savedProduct->id)->exists())))) {
                throw ValidationException::withMessages(['stock_source_product_id' => 'A product with its own stock or transaction history cannot be linked to another stock product.']);
            }
            if ($savedProduct && $savedProduct->stock_source_product_id && DocumentItem::query()->where('product_id', $savedProduct->id)->exists()) {
                if ((int) $savedProduct->stock_source_product_id !== (int) $sourceId) {
                    throw ValidationException::withMessages(['stock_source_product_id' => 'A sales product with transaction history cannot change its stock source.']);
                }
                if ((float) $savedProduct->stock_source_quantity !== (float) ($data['stock_source_quantity'] ?? 1)
                    && DocumentItem::query()->where('product_id', $savedProduct->id)
                        ->where(fn ($query) => $query->whereNull('stock_product_id')->orWhereNull('stock_quantity')->orWhere('stock_product_id', '!=', $sourceId))
                        ->exists()) {
                    throw ValidationException::withMessages(['stock_source_quantity' => 'This product has transactions without a saved stock deduction. Its stock quantity cannot be changed safely.']);
                }
            }
            if ($savedProduct && $savedProduct->salesProducts()->exists()) {
                throw ValidationException::withMessages(['stock_source_product_id' => 'A stock source with linked sales products cannot become a sales product.']);
            }
        } elseif ($savedProduct && $savedProduct->stock_source_product_id && DocumentItem::query()->where('product_id', $savedProduct->id)->exists()) {
            throw ValidationException::withMessages(['stock_source_product_id' => 'A sales product with transaction history cannot remove its stock source.']);
        }
        if (! Auth::user()->organization()->firstOrFail()->gold_store || ! ($data['is_gold_item'] ?? false)) {
            $data['is_gold_item'] = false;
            foreach (['gold_purity', 'gold_gross_weight', 'gold_stone_weight', 'gold_other_weight', 'stone_value', 'making_charge_mode', 'making_charge', 'wastage_percent', 'tag_number', 'hallmark_code'] as $goldField) {
                unset($data[$goldField]);
            }
        }
        $saved = $savedProduct ? tap($savedProduct)->update($data) : Product::create([...$data, 'organization_id' => $organizationId]);

        return response()->json($saved, $savedProduct ? 200 : 201);
    }

    public function deleteProduct(int $product): JsonResponse
    {
        $organizationId = $this->organizationId();
        $saved = Product::query()->where('organization_id', $organizationId)->findOrFail($product);

        $saved->getConnection()->transaction(function () use ($organizationId, $product): void {
            $saved = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($product);
            $hasHistory = DocumentItem::query()->where('product_id', $product)->exists()
                || StockMovement::query()->where('product_id', $product)->exists()
                || StockCheckItem::query()->where('product_id', $product)->exists()
                || StockTransferItem::query()->where('product_id', $product)->exists()
                || ProductDamage::query()->where('product_id', $product)->exists()
                || GoldJob::query()->where('product_id', $product)->exists();

            if ($hasHistory || $saved->salesProducts()->exists() || DocumentItem::query()->where('stock_product_id', $product)->exists() || (float) $saved->quantity_on_hand !== 0.0 || $saved->stocks()->where('quantity_on_hand', '!=', 0)->exists()) {
                throw ValidationException::withMessages(['product' => 'This product has stock or transaction history and cannot be deleted.']);
            }

            $saved->stocks()->delete();
            $saved->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function contacts(): JsonResponse
    {
        return response()->json(Contact::query()->where('organization_id', $this->organizationId())->orderBy('name')->get());
    }

    public function saveContact(Request $request, ?int $contact = null): JsonResponse
    {
        $organizationId = $this->organizationId();
        $savedContact = $contact ? Contact::query()->where('organization_id', $organizationId)->findOrFail($contact) : null;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['customer', 'supplier', 'both'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'company' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'opening_due' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'opening_payable' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'active' => ['boolean'],
        ]);
        $data['credit_limit'] = $data['credit_limit'] ?? $savedContact?->credit_limit ?? 0;
        if ($savedContact) {
            unset($data['opening_due']);
            unset($data['opening_payable']);
        } else {
            $data['opening_due'] = in_array($data['type'], ['customer', 'both'], true) ? ($data['opening_due'] ?? 0) : 0;
            $data['opening_payable'] = in_array($data['type'], ['supplier', 'both'], true) ? ($data['opening_payable'] ?? 0) : 0;
        }
        $saved = $savedContact ? tap($savedContact)->update($data) : Contact::create([...$data, 'organization_id' => $organizationId]);

        return response()->json($saved, $savedContact ? 200 : 201);
    }

    public function deleteContact(int $contact): JsonResponse
    {
        $organizationId = $this->organizationId();
        $saved = Contact::query()->where('organization_id', $organizationId)->findOrFail($contact);

        $saved->getConnection()->transaction(function () use ($organizationId, $contact): void {
            $saved = Contact::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($contact);
            if ($saved->is_default) {
                throw ValidationException::withMessages(['contact' => 'The default customer cannot be deleted.']);
            }

            $hasHistory = Document::query()->where('contact_id', $contact)->exists()
                || GoldJob::query()->where('contact_id', $contact)->exists();

            if ($hasHistory || (float) $saved->opening_due !== 0.0 || (float) $saved->opening_payable !== 0.0) {
                throw ValidationException::withMessages(['contact' => 'This customer or supplier has transactions or an opening balance and cannot be deleted.']);
            }

            $saved->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function documents(Request $request): JsonResponse
    {
        $type = $request->query('type');
        if ($type && ! in_array($type, ['purchase', 'sale', 'resale', 'purchase_return', 'sale_return'], true)) {
            throw ValidationException::withMessages(['type' => 'Invalid document type.']);
        }
        $allowedTypes = $this->allowedDocumentTypes();
        if ($type && ! in_array($type, $allowedTypes, true)) {
            abort(403, 'Your owner has not granted access to this transaction type.');
        }
        $readableTypes = $allowedTypes;
        if (Auth::user()->hasPermission(Permission::SalesReturns)) {
            $readableTypes = array_values(array_unique([...$readableTypes, 'sale', 'resale']));
        }
        if (Auth::user()->hasPermission(Permission::PurchaseReturns)) {
            $readableTypes = array_values(array_unique([...$readableTypes, 'purchase']));
        }

        return response()->json(Document::query()
            ->where('organization_id', $this->organizationId())
            ->where('branch_id', $this->branchId($request, $this->organizationId()))
            ->whereIn('type', $readableTypes)
            ->with('branch', 'contact', 'items.product', 'purchase', 'sale', 'creator:id,name', 'installments', 'purchaseInstallments', 'payments.receiver', 'purchasePayments.receiver', 'purchaseReturns:id,purchase_id,total')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest()
            ->get());
    }

    public function saveDocument(Request $request, InventoryService $inventory): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        $data = $request->validate([
            'type' => ['required', Rule::in(['purchase', 'sale', 'resale', 'purchase_return', 'sale_return'])],
            'sale_channel' => ['sometimes', Rule::in(['standard', 'pos'])],
            'contact_id' => ['required', Rule::exists(app(TenantContext::class)->validationTable('contacts'), 'id')->where('organization_id', $organizationId)],
            'purchase_id' => ['required_if:type,purchase_return', 'nullable', Rule::exists(app(TenantContext::class)->validationTable('documents'), 'id')->where('organization_id', $organizationId)],
            'sale_id' => ['required_if:type,sale_return', 'nullable', Rule::exists(app(TenantContext::class)->validationTable('documents'), 'id')->where('organization_id', $organizationId)],
            'document_date' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'payment_method' => ['required_if:sale_channel,pos', 'nullable', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer', 'credit'])],
            'amount_paid' => ['required_if:sale_channel,pos', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'payment_type' => ['nullable', Rule::in(['full', 'installment', 'due'])],
            'down_payment' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'installment_count' => ['nullable', 'integer', 'min:1', 'max:120'],
            'installment_frequency' => ['nullable', Rule::in(['weekly', 'half_monthly', 'monthly'])],
            'installment_start_date' => ['nullable', 'date', 'after_or_equal:document_date'],
            'first_installment_date' => ['nullable', 'date'],
            'gold_exchange_weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'gold_exchange_purity' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists(app(TenantContext::class)->validationTable('products'), 'id')->where('organization_id', $organizationId), 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.unit' => ['sometimes', 'string', 'max:30'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        $setup = Auth::user()->organization()->firstOrFail()->setup();
        if (Auth::user()->hasRole(UserRole::Manager, UserRole::Staff)
            && ! $setup['store']['allow_staff_custom_dates']
            && in_array($data['type'], ['purchase', 'sale', 'resale'], true)
            && Carbon::parse($data['document_date'])->toDateString() !== now('Asia/Dhaka')->toDateString()) {
            throw ValidationException::withMessages(['document_date' => 'Managers and staff can record sales and purchases only for today.']);
        }
        if ($setup['store']['allow_cash_handover'] && in_array($data['type'], ['sale_return', 'purchase_return'], true)
            && ! in_array($data['payment_method'] ?? null, ['cash', 'card', 'mobile_banking', 'bank_transfer', 'credit'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Choose how the return was refunded or settled.']);
        }
        $feature = match ($data['type']) {
            'resale' => ['sales', 'allow_resales'],
            'sale_return' => ['sales', 'allow_sales_returns'],
            'purchase_return' => ['purchases', 'allow_purchase_returns'],
            default => null,
        };
        if ($feature && ! $setup[$feature[0]][$feature[1]]) {
            throw ValidationException::withMessages(['type' => 'This transaction type is disabled in System Setup.']);
        }
        if (in_array($data['type'], ['sale', 'resale'], true)) {
            if (($data['payment_type'] ?? 'full') === 'due' && ! $setup['sales']['allow_due_sales']) {
                throw ValidationException::withMessages(['payment_type' => 'Due sales are disabled in System Setup.']);
            }
            if (($data['payment_type'] ?? 'full') === 'installment' && ! $setup['sales']['allow_installment_sales']) {
                throw ValidationException::withMessages(['payment_type' => 'Installment sales are disabled in System Setup.']);
            }
        }
        if (($data['sale_channel'] ?? 'standard') === 'pos' && $data['type'] !== 'sale') {
            throw ValidationException::withMessages(['sale_channel' => 'POS checkout can only create a sale.']);
        }
        $requiredPermission = $data['type'] === 'sale' && ($data['sale_channel'] ?? 'standard') === 'pos'
            ? Permission::PosSale
            : $this->permissionForDocumentType($data['type']);
        abort_unless(Auth::user()->hasPermission($requiredPermission), 403, 'Your owner has not granted access to this transaction type.');
        $contact = Contact::query()->where('organization_id', $organizationId)->findOrFail($data['contact_id']);
        $expected = in_array($data['type'], ['purchase', 'purchase_return'], true) ? 'supplier' : 'customer';
        if (! in_array($contact->type, [$expected, 'both'], true)) {
            throw ValidationException::withMessages(['contact_id' => "Select a {$expected} contact."]);
        }

        return response()->json($inventory->createDocument($data, Auth::id(), $organizationId, $branchId), 201);
    }

    public function deleteDocument(Request $request, int $document, DocumentDeletionService $deletion): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        $saved = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->findOrFail($document);
        $permission = $this->permissionForDocumentType($saved->type);
        abort_unless(in_array($saved->type, ['purchase', 'sale', 'resale'], true) && Auth::user()->hasPermission($permission), 403);
        $deletion->delete($document, $organizationId, $branchId);

        return response()->json(['ok' => true]);
    }

    public function deleteDocumentPayment(Request $request, int $document, int $payment, DocumentDeletionService $deletion): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);
        $saved = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->findOrFail($document);
        abort_unless(in_array($saved->type, ['purchase', 'sale', 'resale'], true)
            && Auth::user()->hasPermission($this->permissionForDocumentType($saved->type)), 403);

        return response()->json($deletion->deletePayment($document, $payment, $organizationId, $branchId));
    }

    public function recordSalePayment(Request $request, int $document, InventoryService $inventory): JsonResponse
    {
        $organizationId = $this->organizationId();
        $sale = Document::query()
            ->where('organization_id', $organizationId)
            ->whereIn('type', ['sale', 'resale'])
            ->findOrFail($document);
        abort_unless(
            Auth::user()->hasPermission($this->permissionForDocumentType($sale->type))
                || ($sale->type === 'sale' && $sale->sale_channel === 'pos' && Auth::user()->hasPermission(Permission::PosSale)),
            403,
            'Your owner has not granted access to collect payments for this sale.'
        );
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $sale->branch_id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'payment_date' => ['nullable', 'date'],
            'sale_installment_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($inventory->recordSalePayment($sale, $data, Auth::id(), $organizationId), 201);
    }

    public function recordPurchasePayment(Request $request, int $document): JsonResponse
    {
        $organizationId = $this->organizationId();
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'payment_date' => ['required', 'date'],
            'purchase_installment_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($document, $organizationId, $data) {
            $purchase = Document::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($document);
            abort_unless($purchase->type === 'purchase', 404);
            app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $purchase->branch_id);
            $amountCents = (int) round((float) $data['amount'] * 100);
            $returnsCents = (int) round((float) $purchase->purchaseReturns()->sum('total') * 100);
            $balanceCents = max(0, (int) round((float) $purchase->total * 100) - (int) round((float) $purchase->amount_paid * 100) - $returnsCents);
            if ($amountCents > $balanceCents) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the outstanding supplier balance.']);
            }
            $installment = null;
            if ($purchase->payment_type === 'installment') {
                $installment = PurchaseInstallment::query()->where('document_id', $purchase->id)->lockForUpdate()->find($data['purchase_installment_id'] ?? null);
                if (! $installment) {
                    throw ValidationException::withMessages(['purchase_installment_id' => 'Select an installment from this purchase.']);
                }
                $installmentBalance = (int) round(((float) $installment->amount - (float) $installment->amount_paid) * 100);
                if ($amountCents > $installmentBalance) {
                    throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the selected installment balance.']);
                }
                $installment->update(['amount_paid' => number_format(((int) round((float) $installment->amount_paid * 100) + $amountCents) / 100, 2, '.', '')]);
            }
            $purchase->amount_paid = number_format(((int) round((float) $purchase->amount_paid * 100) + $amountCents) / 100, 2, '.', '');
            $purchase->save();

            return PurchasePayment::create([
                'organization_id' => $organizationId,
                'branch_id' => $purchase->branch_id,
                'document_id' => $purchase->id,
                'purchase_installment_id' => $installment?->id,
                'amount' => number_format($amountCents / 100, 2, '.', ''),
                'payment_method' => $data['payment_method'],
                'payment_date' => $data['payment_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['payment' => $payment->load('receiver:id,name', 'installment'), 'document' => Document::query()->where('organization_id', $organizationId)->with('purchasePayments.receiver:id,name', 'purchaseInstallments')->findOrFail($document)], 201);
    }

    public function movements(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $branchId = $this->branchId($request, $organizationId);

        return response()->json(StockMovement::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->with('branch', 'product', 'document')->when($request->query('product_id'), fn ($q, $id) => $q->where('product_id', $id))->latest()->limit(200)->get());
    }

    public function adjust(Request $request, int $product, InventoryService $inventory): JsonResponse
    {
        $organizationId = $this->organizationId();
        if (! Auth::user()->organization()->firstOrFail()->setup()['store']['allow_stock_adjustments']) {
            throw ValidationException::withMessages(['quantity_change' => 'Stock adjustments are disabled in System Setup.']);
        }
        $branchId = $this->branchId($request, $organizationId);
        $savedProduct = Product::query()->where('organization_id', $organizationId)->findOrFail($product);
        $data = $request->validate([
            'quantity_change' => ['required', 'numeric', 'not_in:0', 'decimal:0,3'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($inventory->adjust($savedProduct, (string) $data['quantity_change'], $data['notes'], Auth::id(), $organizationId, $branchId), 201);
    }

    private function organizationId(): int
    {
        if (Auth::user()?->isSuperadmin() && ! SupportImpersonation::activeFor(request())) {
            abort(403, 'Open a company through View as owner before using its workspace.');
        }

        $organizationId = Auth::user()?->organization_id;
        abort_unless($organizationId, 403, 'Select a client account before using the inventory workspace.');

        return $organizationId;
    }

    private function branchId(Request $request, int $organizationId): int
    {
        return app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, $request->integer('branch_id') ?: null)->id;
    }

    private function productsForBranch(int $organizationId, int $branchId, ?string $search = null)
    {
        $stockByProduct = ProductStock::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->pluck('quantity_on_hand', 'product_id');

        return Product::query()->where('organization_id', $organizationId)
            ->with('category:id,name,active', 'stockSource:id,name,unit,active')
            ->with(['stocks' => fn ($query) => $query->where('branch_id', $branchId)])
            ->when($search, fn ($query, $value) => $query->where(fn ($q) => $q->where('name', 'like', "%{$value}%")->orWhere('sku', 'like', "%{$value}%")->orWhere('barcode', 'like', "%{$value}%")->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$value}%"))))
            ->orderBy('name')->get()
            ->each(function (Product $product) use ($stockByProduct) {
                $product->setAttribute('quantity_on_hand', $product->stocks->first()?->quantity_on_hand ?? '0.000');
                if ($product->stock_source_product_id) {
                    $sourceStock = $stockByProduct[$product->stock_source_product_id] ?? 0;
                    $product->setAttribute('source_quantity_on_hand', number_format((float) $sourceStock, 3, '.', ''));
                    $product->setAttribute('available_quantity', number_format(floor((float) $sourceStock * 1000 / (float) $product->stock_source_quantity) / 1000, 3, '.', ''));
                } else {
                    $product->setAttribute('available_quantity', $product->quantity_on_hand);
                }
                $product->unsetRelation('stocks');
            });
    }

    /** @return list<string> */
    private function allowedDocumentTypes(): array
    {
        return collect(['purchase', 'sale', 'resale', 'purchase_return', 'sale_return'])
            ->filter(fn (string $type) => Auth::user()->hasPermission($this->permissionForDocumentType($type)))
            ->values()
            ->all();
    }

    private function permissionForDocumentType(string $type): Permission
    {
        return match ($type) {
            'purchase' => Permission::Purchases,
            'sale' => Permission::Sales,
            'sale_return' => Permission::SalesReturns,
            'resale' => Permission::Resales,
            'purchase_return' => Permission::PurchaseReturns,
        };
    }
}
