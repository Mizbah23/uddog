<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SupportImpersonation;
use App\Permission;
use App\ReportType;
use App\Services\CashMovementReport;
use App\Services\GeneralLedgerReport;
use App\Services\ProductLifecycleLedgerReport;
use App\Services\SupplierAdvanceReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index(Request $request, GeneralLedgerReport $generalLedger, ProductLifecycleLedgerReport $productLifecycleLedger, CashMovementReport $cashMovement, SupplierAdvanceReport $supplierAdvances): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required', Rule::enum(ReportType::class)],
            'branch_id' => ['nullable', in_array($request->input('report'), ['profit_loss', 'product_ledger', 'product_lifecycle_ledger'], true) ? 'regex:/\A(?:all|[1-9][0-9]*)\z/' : 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'account' => ['nullable', Rule::in(array_keys(GeneralLedgerReport::accounts()))],
        ]);

        $organizationId = Auth::user()?->organization_id;
        if (Auth::user()?->isSuperadmin() && ! SupportImpersonation::activeFor($request)) {
            abort(403, 'Open a company through View as owner before using its workspace.');
        }
        abort_unless($organizationId, 403, 'Select a client account before using reports.');

        $report = $data['report'];
        abort_unless(Auth::user()->hasReportAccess(ReportType::from($report)), 403, 'Your owner has not granted access to this report.');
        if ($report === 'cash_movement') {
            abort_unless(Organization::query()->findOrFail($organizationId)->setup()['store']['allow_cash_handover'], 403, 'Cash movement is disabled in System Setup.');
        }

        $branchAccess = app(BranchAccess::class);
        $allBranches = in_array($report, ['profit_loss', 'product_ledger', 'product_lifecycle_ledger'], true) && ($data['branch_id'] ?? null) === 'all';
        if ($allBranches) {
            $branches = $branchAccess->query(Auth::user(), $organizationId)->where('active', true)->get(['id', 'name']);
            abort_unless($branches->count() > 1, 403, 'Multiple branch access is required to view an all-branch report.');
            $branchIds = $branches->pluck('id')->all();
            $branchId = null;
            $branchName = 'All permitted branches';
        } else {
            $branch = $branchAccess->activeBranch(Auth::user(), $organizationId, $request->integer('branch_id') ?: null);
            $branchId = $branch->id;
            $branchIds = [$branchId];
            $branchName = $branch->name;
        }
        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;
        $search = trim($data['search'] ?? '');
        if ($report === ReportType::SupplierTT->value) {
            return response()->json(['report' => $report, ...$supplierAdvances->build($organizationId, $branchId, $dateFrom, $dateTo, $search)]);
        }
        $visibleTypes = collect();
        if (Auth::user()->hasPermission(Permission::Sales)) {
            $visibleTypes->push('sale');
        }
        if (Auth::user()->hasPermission(Permission::Resales)) {
            $visibleTypes->push('resale');
        }
        if (Auth::user()->hasPermission(Permission::SalesReturns)) {
            $visibleTypes->push('sale', 'resale', 'sale_return');
        }
        if (Auth::user()->hasPermission(Permission::Purchases)) {
            $visibleTypes->push('purchase');
        }
        if (Auth::user()->hasPermission(Permission::PurchaseReturns)) {
            $visibleTypes->push('purchase', 'purchase_return');
        }
        if ($report === 'customer_ledger') {
            $visibleTypes->push('sale', 'resale', 'sale_return');
        }
        if ($report === 'supplier_ledger') {
            $visibleTypes->push('purchase', 'purchase_return');
        }
        $visibleTypes = $visibleTypes->unique()->values()->all();
        $documents = Document::query()->where('organization_id', $organizationId)->whereIn('branch_id', $branchIds)->whereIn('type', $visibleTypes)
            ->when($dateFrom, fn ($query) => $query->whereDate('document_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('document_date', '<=', $dateTo));

        $result = match ($report) {
            'sales' => $this->sales($documents),
            'purchases' => $this->purchases($documents),
            'profit_loss' => $this->profitLoss($documents, $organizationId, $branchIds, $branchName, $dateFrom, $dateTo),
            'product_ledger' => $this->productLedger($documents, $branchName, $search),
            'product_lifecycle_ledger' => $productLifecycleLedger->build($organizationId, $branchIds, $branchName, $data['product_id'] ?? null, $dateFrom, $dateTo),
            'employee_sales' => $this->employeeSales($documents),
            'stock' => $this->stock($organizationId, $branchId, $search),
            'adjustments' => $this->adjustments($organizationId, $branchId, $dateFrom, $dateTo),
            'barcode_products' => $this->barcodeProducts($organizationId, $branchId, $search),
            'barcode_sales' => $this->barcodeSales($documents, $search),
            'categories' => $this->categories($organizationId, $branchId, $documents, Auth::user()->hasPermission(Permission::Sales) || Auth::user()->hasPermission(Permission::Resales)),
            'customers' => $this->customers($organizationId, $branchId),
            'customer_ledger' => $this->customerLedger($documents, $data['contact_id'] ?? null),
            'customer_due' => $this->customerDue($documents),
            'suppliers' => $this->suppliers($organizationId, $branchId),
            'supplier_ledger' => $this->supplierLedger($documents, $data['contact_id'] ?? null),
            'supplier_due' => $this->supplierDue($documents),
            'general_ledger' => $generalLedger->build($organizationId, $branchId, $dateFrom, $dateTo, $data['account'] ?? null),
            'cash_movement' => $cashMovement->build($organizationId, $branchId, $dateFrom, $dateTo),
        };

        return response()->json(['report' => $report, ...$result]);
    }

    public function customerLedgerInvoice(Request $request, int $document): JsonResponse
    {
        return $this->ledgerInvoice($request, $document, ReportType::CustomerLedger, ['sale', 'resale', 'sale_return'], ['customer', 'both']);
    }

    public function supplierLedgerInvoice(Request $request, int $document): JsonResponse
    {
        return $this->ledgerInvoice($request, $document, ReportType::SupplierLedger, ['purchase', 'purchase_return'], ['supplier', 'both']);
    }

    private function ledgerInvoice(Request $request, int $document, ReportType $report, array $types, array $contactTypes): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user?->hasReportAccess($report), 403);
        if ($user->isSuperadmin() && ! SupportImpersonation::activeFor($request)) {
            abort(403, 'Open a company through View as owner before using its workspace.');
        }

        $branchId = app(BranchAccess::class)->activeBranch($user, $user->organization_id, $request->integer('branch_id') ?: null)->id;
        $invoice = Document::query()->where('organization_id', $user->organization_id)->where('branch_id', $branchId)
            ->whereIn('type', $types)
            ->whereHas('contact', fn ($query) => $query->whereIn('type', $contactTypes))
            ->findOrFail($document);

        return response()->json($invoice->load('branch', 'contact', 'items.product', 'purchase', 'sale', 'creator:id,name', 'installments', 'purchaseInstallments', 'payments.receiver', 'purchasePayments.receiver', 'purchaseReturns:id,purchase_id,total'));
    }

    private function sales(Builder $documents): array
    {
        $rows = (clone $documents)->whereIn('type', ['sale', 'resale', 'sale_return'])->with(['contact:id,name', 'creator:id,name'])->orderByDesc('document_date')->get()
            ->map(fn (Document $document) => [
                'date' => $document->document_date,
                'recorded_at' => $document->created_at,
                'number' => $document->number,
                'kind' => $document->type,
                'customer' => $document->contact?->name ?? '—',
                'employee' => $document->creator?->name ?? '—',
                'total' => $document->type === 'sale_return' ? -(float) $document->total : (float) $document->total,
                'paid' => $document->type === 'sale_return' ? 0 : (float) $document->amount_paid,
                'due' => $document->type === 'sale_return' ? 0 : (float) $document->balance_due,
                'payment_status' => $document->payment_status,
            ])->values();

        return $this->table(['Date', 'Recorded time', 'Invoice', 'Type', 'Customer', 'Employee', 'Net total', 'Paid', 'Due', 'Payment'], $rows, ['net_sales' => $rows->sum('total'), 'paid' => $rows->sum('paid'), 'due' => $rows->sum('due')]);
    }

    private function purchases(Builder $documents): array
    {
        $rows = (clone $documents)->whereIn('type', ['purchase', 'purchase_return'])->with(['contact:id,name', 'creator:id,name'])->orderByDesc('document_date')->get()
            ->map(fn (Document $document) => ['date' => $document->document_date, 'number' => $document->number, 'kind' => $document->type, 'supplier' => $document->contact?->name ?? '—', 'employee' => $document->creator?->name ?? '—', 'total' => $document->type === 'purchase_return' ? -(float) $document->total : (float) $document->total])->values();

        return $this->table(['Date', 'Reference', 'Type', 'Supplier', 'Employee', 'Net total'], $rows, ['net_purchases' => $rows->sum('total')]);
    }

    private function profitLoss(Builder $documents, int $organizationId, array $branchIds, string $branchName, ?string $dateFrom, ?string $dateTo): array
    {
        $sales = (clone $documents)->whereIn('type', ['sale', 'resale'])->with('items')->get();
        $returnDocuments = (clone $documents)->where('type', 'sale_return')->with('items')->get();
        $totalSales = $sales->sum(fn (Document $document) => (float) $document->total);
        $returns = $returnDocuments->sum(fn (Document $document) => (float) $document->total);
        $returnProfit = $returnDocuments->sum(fn (Document $document) => $document->items->sum(fn ($item) => (float) $item->line_total - ((float) $item->cost_price * (float) $item->quantity)));
        $grossProfit = $sales->sum(fn (Document $document) => (float) $document->total_profit);
        $netSales = $totalSales - $returns;
        $expenses = Expense::query()->where('organization_id', $organizationId)->whereIn('branch_id', $branchIds)
            ->when($dateFrom, fn ($query) => $query->whereDate('expense_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('expense_date', '<=', $dateTo))->get();
        $expenseTotal = $expenses->sum(fn (Expense $expense) => (float) $expense->amount);
        $grossProfitAfterReturns = $grossProfit - $returnProfit;
        $netProfit = $grossProfitAfterReturns - $expenseTotal;
        $costOfSales = $sales->sum(fn (Document $document) => $document->items->sum(fn ($item) => (float) $item->cost_price * (float) $item->quantity))
            - $returnDocuments->sum(fn (Document $document) => $document->items->sum(fn ($item) => (float) $item->cost_price * (float) $item->quantity));
        $salesAdjustments = $netSales - $grossProfitAfterReturns - $costOfSales;
        $totalCostsAndExpenses = $netSales - $netProfit;
        $income = collect([
            ['account' => 'Total sales', 'amount' => $totalSales],
            ['account' => 'Sales returns', 'amount' => -$returns],
        ])->filter(fn (array $item) => $item['amount'] != 0)->values();
        $expenseAccounts = collect([
            ['account' => 'Cost of sales', 'amount' => $costOfSales],
            ['account' => 'Tax & sales adjustments', 'amount' => $salesAdjustments],
        ])->filter(fn (array $item) => $item['amount'] != 0)->concat($expenses->groupBy(fn (Expense $expense) => $expense->category ?: 'Uncategorized')
            ->map(fn ($items, string $category) => ['account' => $category, 'amount' => $items->sum(fn (Expense $expense) => (float) $expense->amount)])
            ->sortBy('account')->values())->values();
        $rows = collect(range(0, max($income->count(), $expenseAccounts->count(), 1) - 1))
            ->map(fn (int $index) => [
                'income_account' => $income->get($index)['account'] ?? '',
                'income_amount' => $income->get($index)['amount'] ?? null,
                'expense_account' => $expenseAccounts->get($index)['account'] ?? '',
                'expense_amount' => $expenseAccounts->get($index)['amount'] ?? null,
            ])->push([
                'income_account' => 'Total income',
                'income_amount' => $netSales,
                'expense_account' => 'Total costs & expenses',
                'expense_amount' => $totalCostsAndExpenses,
            ])->push([
                'income_account' => 'Profit / loss for this period',
                'income_amount' => $netProfit,
                'expense_account' => '',
                'expense_amount' => null,
            ]);

        return [
            ...$this->table(['Income Head', 'Income amount', 'Expense Head', 'Expense amount'], $rows, ['total_sales' => $totalSales, 'sales_returns' => $returns, 'net_sales' => $netSales, 'cost_of_sales' => $costOfSales, 'gross_profit' => $grossProfitAfterReturns, 'operating_expenses' => $expenseTotal, 'total_costs_and_expenses' => $totalCostsAndExpenses, 'net_profit' => $netProfit]),
            'income' => $income,
            'expenses' => $expenseAccounts,
            'branch_name' => $branchName,
        ];
    }

    private function productLedger(Builder $documents, string $branchName, string $search): array
    {
        $documents = (clone $documents)->whereIn('type', ['sale', 'resale', 'sale_return'])
            ->with('items.product:id,name,sku,barcode')->get();
        $products = [];

        foreach ($documents as $document) {
            $items = $document->items->values();
            $subtotalCents = $items->sum(fn ($item) => (int) round((float) $item->line_total * 100));
            $discountCents = (int) round((float) $document->discount * 100);
            $interestCents = (int) round((float) $document->interest_amount * 100);
            $remainingDiscount = $discountCents;
            $remainingInterest = $interestCents;
            $direction = $document->type === 'sale_return' ? -1 : 1;

            foreach ($items as $index => $item) {
                $lineCents = (int) round((float) $item->line_total * 100);
                $share = $subtotalCents > 0 ? $lineCents / $subtotalCents : 1 / max(1, $items->count());
                $isLast = $index === $items->count() - 1;
                $itemDiscount = $isLast ? $remainingDiscount : min($remainingDiscount, (int) round($discountCents * $share));
                $itemInterest = $isLast ? $remainingInterest : min($remainingInterest, (int) round($interestCents * $share));
                $remainingDiscount -= $itemDiscount;
                $remainingInterest -= $itemInterest;

                $product = $item->product;
                if ($search !== '' && ! str_contains(mb_strtolower(($product?->name ?? '').' '.($product?->sku ?? '').' '.($product?->barcode ?? '')), mb_strtolower($search))) {
                    continue;
                }

                $productId = $item->product_id;
                $products[$productId] ??= [
                    'product' => $product?->name ?? "Product #{$productId}",
                    'quantity' => 0,
                    'sales_cents' => 0,
                    'cost_cents' => 0,
                    'stock_profit_cents' => 0,
                    'interest_cents' => 0,
                    'period_profit_cents' => 0,
                ];
                $quantity = (float) $item->quantity * $direction;
                $salesCents = ($lineCents - $itemDiscount) * $direction;
                $costCents = (int) round((float) $item->cost_price * (float) $item->quantity * 100) * $direction;
                $stockProfitCents = $salesCents - $costCents;
                $installmentProfitCents = $itemInterest * $direction;
                $products[$productId]['quantity'] += $quantity;
                $products[$productId]['sales_cents'] += $salesCents;
                $products[$productId]['cost_cents'] += $costCents;
                $products[$productId]['stock_profit_cents'] += $stockProfitCents;
                $products[$productId]['interest_cents'] += $installmentProfitCents;
                $products[$productId]['period_profit_cents'] += $stockProfitCents + $installmentProfitCents;
            }
        }

        $rows = collect($products)->sortBy('product', SORT_NATURAL | SORT_FLAG_CASE)->values()
            ->map(fn (array $product, int $index) => [
                'serial' => $index + 1,
                'product' => $product['product'],
                'quantity' => round($product['quantity'], 3),
                'total_sales' => $product['sales_cents'] / 100,
                'costing' => $product['cost_cents'] / 100,
                'stock_profit' => $product['stock_profit_cents'] / 100,
                'installment_profit' => $product['interest_cents'] / 100,
                'period_profit' => $product['period_profit_cents'] / 100,
            ]);

        return [
            ...$this->table(
                ['SL', 'Product name', 'Quantity (Qty)', 'Total sales', 'Costing', 'Stock profit', 'Installment profit', 'Profit / loss for period'],
                $rows,
                [
                    'quantity' => round($rows->sum('quantity'), 3),
                    'total_sales' => $rows->sum('total_sales'),
                    'costing' => $rows->sum('costing'),
                    'stock_profit' => $rows->sum('stock_profit'),
                    'installment_profit' => $rows->sum('installment_profit'),
                    'period_profit' => $rows->sum('period_profit'),
                ],
            ),
            'branch_name' => $branchName,
        ];
    }

    private function employeeSales(Builder $documents): array
    {
        $rows = (clone $documents)->whereIn('type', ['sale', 'resale'])->with('creator:id,name')->get()->groupBy(fn (Document $document) => $document->created_by)
            ->map(function ($sales) {
                return ['employee' => $sales->first()->creator?->name ?? '—', 'transactions' => $sales->count(), 'sales' => $sales->sum(fn (Document $document) => (float) $document->total), 'paid' => $sales->sum(fn (Document $document) => (float) $document->amount_paid)];
            })->values();

        return $this->table(['Employee', 'Sales count', 'Sales total', 'Collected'], $rows, ['sales' => $rows->sum('sales'), 'collected' => $rows->sum('paid')]);
    }

    private function stock(int $organizationId, int $branchId, string $search): array
    {
        $movements = StockMovement::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereIn('type', ['purchase', 'purchase_return', 'sale', 'resale', 'sale_return', 'damage'])
            ->selectRaw('product_id, type, SUM(quantity_change) as quantity_total')
            ->groupBy('product_id', 'type')->get()->groupBy('product_id');
        $rows = Product::query()->where('organization_id', $organizationId)->whereNull('stock_source_product_id')->with('category:id,name')->with(['stocks' => fn ($query) => $query->where('branch_id', $branchId)])
            ->when($search, fn ($query) => $query->where(fn ($builder) => $builder->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")))
            ->orderBy('name')->get()->values()->map(function (Product $product, int $index) use ($movements) {
                $byType = $movements->get($product->id, collect())->pluck('quantity_total', 'type');
                $purchases = (float) ($byType->get('purchase') ?? 0) + (float) ($byType->get('purchase_return') ?? 0);
                $sales = -((float) ($byType->get('sale') ?? 0) + (float) ($byType->get('resale') ?? 0) + (float) ($byType->get('sale_return') ?? 0));
                $damaged = -(float) ($byType->get('damage') ?? 0);
                $good = (float) ($product->stocks->first()?->quantity_on_hand ?? 0);
                $rate = (float) $product->cost_price;

                return [
                    'serial' => $index + 1, 'product' => $product->name, 'unit' => $product->unit,
                    'purchases' => round($purchases, 3), 'sales' => round($sales, 3),
                    'actual_stock' => round($good + $damaged, 3), 'damaged_stock' => round($damaged, 3),
                    'good_stock' => round($good, 3), 'rate' => $rate,
                    'total_value' => round($good * $rate, 2),
                ];
            });

        $totals = [
            'purchases' => round($rows->sum('purchases'), 3), 'sales' => round($rows->sum('sales'), 3),
            'actual_stock' => round($rows->sum('actual_stock'), 3),
            'damaged_stock' => round($rows->sum('damaged_stock'), 3),
            'good_stock' => round($rows->sum('good_stock'), 3),
            'total_value' => round($rows->sum('total_value'), 2),
        ];

        return [
            ...$this->table(['SL', 'Product', 'Unit', 'Total purchases', 'Total sales', 'Actual stock', 'Damaged stock', 'Good stock', 'Rate', 'Total value'], $rows, ['products' => $rows->count(), 'stock_value' => $totals['total_value']]),
            'totals' => $totals,
            'note' => 'Lifetime net purchases and sales are shown in base stock units. Actual stock includes recorded damage; good stock is the current branch quantity available for sale. Adjustments and branch transfers also affect current stock. Rate is the current average purchase cost. Quantity totals across unlike units are arithmetic totals.',
        ];
    }

    private function adjustments(int $organizationId, int $branchId, ?string $dateFrom, ?string $dateTo): array
    {
        $rows = StockMovement::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->whereIn('type', ['adjustment', 'stock_check'])
            ->when($dateFrom, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->with(['product:id,name,sku,unit', 'branch:id,name'])->latest()->get()
            ->map(fn (StockMovement $movement) => ['date' => $movement->created_at, 'type' => $movement->type, 'product' => $movement->product?->name ?? '—', 'sku' => $movement->product?->sku ?? '—', 'change' => (float) $movement->quantity_change, 'balance' => (float) $movement->balance_after, 'unit' => $movement->product?->unit ?? '', 'notes' => $movement->notes ?? '—']);

        return $this->table(['Date', 'Type', 'Product', 'SKU', 'Change', 'Balance', 'Unit', 'Notes'], $rows, ['movements' => $rows->count(), 'net_change' => $rows->sum('change')]);
    }

    private function barcodeProducts(int $organizationId, int $branchId, string $search): array
    {
        $rows = Product::query()->where('organization_id', $organizationId)->whereNotNull('barcode')->with('category:id,name')->with(['stocks' => fn ($query) => $query->where('branch_id', $branchId)])
            ->when($search, fn ($query) => $query->where(fn ($builder) => $builder->where('barcode', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('barcode')->get()->map(fn (Product $product) => ['barcode' => $product->barcode, 'product' => $product->name, 'sku' => $product->sku ?? '—', 'category' => $product->category?->name ?? 'Uncategorized', 'quantity' => (float) ($product->stocks->first()?->quantity_on_hand ?? 0), 'unit' => $product->unit, 'sale_price' => (float) $product->sale_price]);

        return $this->table(['Barcode', 'Product', 'SKU', 'Category', 'On hand', 'Unit', 'Sale price'], $rows, ['products' => $rows->count()]);
    }

    private function barcodeSales(Builder $documents, string $search): array
    {
        $rows = (clone $documents)->whereIn('type', ['sale', 'resale'])->with(['items.product:id,name,barcode,sku,unit', 'contact:id,name'])->get()
            ->flatMap(fn (Document $document) => $document->items->filter(fn ($item) => ! $search || str_contains(strtolower($item->product?->barcode ?? ''), strtolower($search)) || str_contains(strtolower($item->product?->name ?? ''), strtolower($search)))
                ->map(fn ($item) => ['date' => $document->document_date, 'invoice' => $document->number, 'barcode' => $item->product?->barcode ?? '—', 'product' => $item->product?->name ?? '—', 'customer' => $document->contact?->name ?? '—', 'quantity' => (float) $item->quantity, 'unit_price' => (float) $item->unit_price, 'total' => (float) $item->line_total]))->values();

        return $this->table(['Date', 'Invoice', 'Barcode', 'Product', 'Customer', 'Quantity', 'Unit price', 'Line total'], $rows, ['quantity' => $rows->sum('quantity'), 'sales' => $rows->sum('total')]);
    }

    private function categories(int $organizationId, int $branchId, Builder $documents, bool $includeSales): array
    {
        $soldItems = $includeSales ? (clone $documents)->whereIn('type', ['sale', 'resale'])->with('items.product')->get()->flatMap(fn (Document $document) => $document->items) : collect();
        $rows = Category::query()->where('organization_id', $organizationId)->with(['products.stocks' => fn ($query) => $query->where('branch_id', $branchId)])->orderBy('name')->get()
            ->map(function (Category $category) use ($soldItems) {
                $products = $category->products;
                $ids = $products->pluck('id');
                $items = $soldItems->filter(fn ($item) => $ids->contains($item->product_id));

                return ['category' => $category->name, 'products' => $products->count(), 'stock' => $products->sum(fn ($product) => (float) ($product->stocks->first()?->quantity_on_hand ?? 0)), 'sales' => $items->sum(fn ($item) => (float) $item->line_total), 'units_sold' => $items->sum(fn ($item) => (float) $item->quantity)];
            })->values();

        return $this->table(['Category', 'Products', 'Units in stock', 'Sales', 'Units sold'], $rows, ['sales' => $rows->sum('sales'), 'products' => $rows->sum('products')]);
    }

    private function customers(int $organizationId, int $branchId): array
    {
        $salesByContact = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->whereIn('type', ['sale', 'resale'])
            ->get(['contact_id', 'total', 'amount_paid'])->groupBy('contact_id');
        $rows = Contact::query()->where('organization_id', $organizationId)->whereIn('type', ['customer', 'both'])->orderBy('name')->get()
            ->map(function (Contact $contact) use ($salesByContact) {
                $sales = $salesByContact->get($contact->id, collect());

                return ['customer' => $contact->name, 'phone' => $contact->phone ?? '—', 'sales_count' => $sales->count(), 'sales' => $sales->sum(fn ($document) => (float) $document->total), 'paid' => $sales->sum(fn ($document) => (float) $document->amount_paid), 'due' => $sales->sum(fn ($document) => (float) $document->balance_due) + (float) $contact->opening_due, 'credit_limit' => (float) $contact->credit_limit];
            })->values();

        return $this->table(['Customer', 'Phone', 'Sales count', 'Sales', 'Paid', 'Due', 'Credit limit'], $rows, ['sales' => $rows->sum('sales'), 'paid' => $rows->sum('paid'), 'due' => $rows->sum('due')]);
    }

    private function customerLedger(Builder $documents, ?int $contactId): array
    {
        if ($contactId) {
            Contact::query()->where('organization_id', Auth::user()->organization_id)->findOrFail($contactId);
        }
        $rows = (clone $documents)->whereIn('type', ['sale', 'resale', 'sale_return'])->whereHas('contact', fn ($query) => $query->whereIn('type', ['customer', 'both']))
            ->when($contactId, fn ($query) => $query->where('contact_id', $contactId))->with('contact:id,name')->orderBy('document_date')->get()
            ->map(fn (Document $document) => ['date' => $document->document_date, 'customer' => $document->contact?->name ?? '—', 'reference' => $document->number, 'type' => $document->type, 'debit' => $document->type === 'sale_return' ? 0 : (float) $document->total, 'credit' => $document->type === 'sale_return' ? (float) $document->total : (float) $document->amount_paid, 'balance' => (float) $document->balance_due, 'document_id' => $document->id]);

        return $this->table(['Date', 'Customer', 'Reference', 'Type', 'Debit', 'Credit', 'Open balance'], $rows, ['debit' => $rows->sum('debit'), 'credit' => $rows->sum('credit'), 'balance' => $rows->sum('balance')]);
    }

    private function customerDue(Builder $documents): array
    {
        $rows = (clone $documents)->whereIn('type', ['sale', 'resale'])->whereRaw('(total - amount_paid) > 0')->with('contact:id,name,phone')->orderByDesc('document_date')->get()
            ->map(fn (Document $document) => ['date' => $document->document_date, 'invoice' => $document->number, 'customer' => $document->contact?->name ?? '—', 'phone' => $document->contact?->phone ?? '—', 'total' => (float) $document->total, 'paid' => (float) $document->amount_paid, 'due' => (float) $document->balance_due, 'status' => $document->payment_status]);

        return $this->table(['Date', 'Invoice', 'Customer', 'Phone', 'Total', 'Paid', 'Due', 'Status'], $rows, ['invoices' => $rows->count(), 'due' => $rows->sum('due')]);
    }

    private function suppliers(int $organizationId, int $branchId): array
    {
        $documentsByContact = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereIn('type', ['purchase', 'purchase_return'])
            ->get(['contact_id', 'type', 'total', 'amount_paid'])->groupBy('contact_id');
        $rows = Contact::query()->where('organization_id', $organizationId)->whereIn('type', ['supplier', 'both'])->orderBy('name')->get()
            ->map(function (Contact $contact) use ($documentsByContact) {
                $documents = $documentsByContact->get($contact->id, collect());
                $purchases = $documents->where('type', 'purchase');
                $returns = $documents->where('type', 'purchase_return')->sum(fn (Document $document) => (float) $document->total);
                $purchaseTotal = $purchases->sum(fn (Document $document) => (float) $document->total);
                $paid = $purchases->sum(fn (Document $document) => (float) $document->amount_paid);
                $openingPayable = (float) $contact->opening_payable;

                return ['supplier' => $contact->name, 'phone' => $contact->phone ?? '—', 'purchases_count' => $purchases->count(), 'purchases' => $purchaseTotal, 'returns' => $returns, 'paid' => $paid, 'opening_payable' => $openingPayable, 'due' => $openingPayable + $purchaseTotal - $returns - $paid];
            })->values();

        return $this->table(['Supplier', 'Phone', 'Purchases count', 'Purchases', 'Returns', 'Paid', 'Opening payable', 'Due'], $rows, ['suppliers' => $rows->count(), 'purchases' => $rows->sum('purchases'), 'returns' => $rows->sum('returns'), 'paid' => $rows->sum('paid'), 'due' => $rows->sum('due')]);
    }

    private function supplierLedger(Builder $documents, ?int $contactId): array
    {
        if ($contactId) {
            Contact::query()->where('organization_id', Auth::user()->organization_id)->whereIn('type', ['supplier', 'both'])->findOrFail($contactId);
        }
        $rows = (clone $documents)->whereIn('type', ['purchase', 'purchase_return'])
            ->whereHas('contact', fn ($query) => $query->whereIn('type', ['supplier', 'both']))
            ->when($contactId, fn ($query) => $query->where('contact_id', $contactId))
            ->with(['contact:id,name', 'purchaseReturns:id,purchase_id,total'])
            ->orderBy('document_date')->orderBy('id')->get()
            ->map(fn (Document $document) => [
                'date' => $document->document_date,
                'supplier' => $document->contact?->name ?? '—',
                'reference' => $document->number,
                'type' => $document->type,
                'debit' => $document->type === 'purchase_return' ? (float) $document->total : (float) $document->amount_paid,
                'credit' => $document->type === 'purchase_return' ? 0 : (float) $document->total,
                'balance' => $document->type === 'purchase_return' ? 0 : (float) $document->balance_due,
                'document_id' => $document->id,
            ]);

        return $this->table(['Date', 'Supplier', 'Reference', 'Type', 'Debit', 'Credit', 'Open balance'], $rows, ['debit' => $rows->sum('debit'), 'credit' => $rows->sum('credit'), 'balance' => $rows->sum('balance')]);
    }

    private function supplierDue(Builder $documents): array
    {
        $rows = (clone $documents)->where('type', 'purchase')
            ->whereHas('contact', fn ($query) => $query->whereIn('type', ['supplier', 'both']))
            ->with(['contact:id,name,phone', 'purchaseReturns:id,purchase_id,total'])
            ->orderByDesc('document_date')->get()
            ->filter(fn (Document $document) => (float) $document->balance_due > 0)
            ->map(function (Document $document) {
                $returned = $document->purchaseReturns->sum(fn (Document $return) => (float) $return->total);

                return ['date' => $document->document_date, 'invoice' => $document->number, 'supplier' => $document->contact?->name ?? '—', 'phone' => $document->contact?->phone ?? '—', 'total' => (float) $document->total, 'returned' => $returned, 'paid' => (float) $document->amount_paid, 'due' => (float) $document->balance_due, 'status' => (float) $document->amount_paid + $returned > 0 ? 'Partial due' : 'Due'];
            })->values();

        return $this->table(['Date', 'Invoice', 'Supplier', 'Phone', 'Total', 'Returned', 'Paid', 'Due', 'Status'], $rows, ['invoices' => $rows->count(), 'due' => $rows->sum('due')]);
    }

    private function table(array $columns, $rows, array $summary): array
    {
        return ['columns' => $columns, 'rows' => $rows->values(), 'summary' => $summary];
    }
}
