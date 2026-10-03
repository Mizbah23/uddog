<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SupportImpersonation;
use App\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report' => ['required', Rule::in(['sales', 'purchases', 'profit_loss', 'employee_sales', 'stock', 'adjustments', 'barcode_products', 'barcode_sales', 'categories', 'customers', 'customer_ledger', 'customer_due'])],
            'branch_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
            'contact_id' => ['nullable', 'integer'],
        ]);

        $organizationId = Auth::user()?->organization_id;
        if (Auth::user()?->isSuperadmin() && ! SupportImpersonation::activeFor($request)) {
            abort(403, 'Open a company through View as owner before using its workspace.');
        }
        abort_unless($organizationId, 403, 'Select a client account before using reports.');

        $report = $data['report'];
        $permissions = match ($report) {
            'sales' => [Permission::Sales, Permission::Resales, Permission::SalesReturns],
            'employee_sales', 'barcode_sales', 'profit_loss' => [Permission::Sales, Permission::Resales],
            'customer_due' => [Permission::Sales, Permission::Resales],
            'purchases' => [Permission::Purchases, Permission::PurchaseReturns],
            'stock' => [Permission::Inventory, Permission::Products],
            'adjustments' => [Permission::StockAdjustments],
            'barcode_products' => [Permission::Products],
            'categories' => [Permission::Categories, Permission::Products],
            'customers', 'customer_ledger' => [Permission::Contacts],
        };
        abort_unless(collect($permissions)->contains(fn (Permission $permission) => Auth::user()->hasPermission($permission)), 403, 'Your owner has not granted access to this report.');

        $branchId = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, $request->integer('branch_id') ?: null)->id;
        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;
        $search = trim($data['search'] ?? '');
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
        $visibleTypes = $visibleTypes->unique()->values()->all();
        $documents = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)->whereIn('type', $visibleTypes)
            ->when($dateFrom, fn ($query) => $query->whereDate('document_date', '>=', $dateFrom))
            ->when($dateTo, fn ($query) => $query->whereDate('document_date', '<=', $dateTo));

        $result = match ($report) {
            'sales' => $this->sales($documents),
            'purchases' => $this->purchases($documents),
            'profit_loss' => $this->profitLoss($documents),
            'employee_sales' => $this->employeeSales($documents),
            'stock' => $this->stock($organizationId, $branchId, $search),
            'adjustments' => $this->adjustments($organizationId, $branchId, $dateFrom, $dateTo),
            'barcode_products' => $this->barcodeProducts($organizationId, $branchId, $search),
            'barcode_sales' => $this->barcodeSales($documents, $search),
            'categories' => $this->categories($organizationId, $branchId, $documents, Auth::user()->hasPermission(Permission::Sales) || Auth::user()->hasPermission(Permission::Resales)),
            'customers' => $this->customers($organizationId, $branchId),
            'customer_ledger' => $this->customerLedger($documents, $data['contact_id'] ?? null),
            'customer_due' => $this->customerDue($documents),
        };

        return response()->json(['report' => $report, ...$result]);
    }

    private function sales(Builder $documents): array
    {
        $rows = (clone $documents)->whereIn('type', ['sale', 'resale', 'sale_return'])->with(['contact:id,name', 'creator:id,name'])->orderByDesc('document_date')->get()
            ->map(fn (Document $document) => [
                'date' => $document->document_date,
                'number' => $document->number,
                'kind' => $document->type,
                'customer' => $document->contact?->name ?? '—',
                'employee' => $document->creator?->name ?? '—',
                'total' => $document->type === 'sale_return' ? -(float) $document->total : (float) $document->total,
                'paid' => $document->type === 'sale_return' ? 0 : (float) $document->amount_paid,
                'due' => $document->type === 'sale_return' ? 0 : (float) $document->balance_due,
                'payment_status' => $document->payment_status,
            ])->values();

        return $this->table(['Date', 'Invoice', 'Type', 'Customer', 'Employee', 'Net total', 'Paid', 'Due', 'Payment'], $rows, ['net_sales' => $rows->sum('total'), 'paid' => $rows->sum('paid'), 'due' => $rows->sum('due')]);
    }

    private function purchases(Builder $documents): array
    {
        $rows = (clone $documents)->whereIn('type', ['purchase', 'purchase_return'])->with(['contact:id,name', 'creator:id,name'])->orderByDesc('document_date')->get()
            ->map(fn (Document $document) => ['date' => $document->document_date, 'number' => $document->number, 'kind' => $document->type, 'supplier' => $document->contact?->name ?? '—', 'employee' => $document->creator?->name ?? '—', 'total' => $document->type === 'purchase_return' ? -(float) $document->total : (float) $document->total])->values();

        return $this->table(['Date', 'Reference', 'Type', 'Supplier', 'Employee', 'Net total'], $rows, ['net_purchases' => $rows->sum('total')]);
    }

    private function profitLoss(Builder $documents): array
    {
        $sales = (clone $documents)->whereIn('type', ['sale', 'resale'])->with('items')->get();
        $returnDocuments = (clone $documents)->where('type', 'sale_return')->with('items')->get();
        $returns = $returnDocuments->sum(fn (Document $document) => (float) $document->total);
        $returnProfit = $returnDocuments->sum(fn (Document $document) => $document->items->sum(fn ($item) => ((float) $item->unit_price - (float) $item->cost_price) * (float) $item->quantity));
        $grossProfit = $sales->sum(fn (Document $document) => (float) $document->total_profit);
        $netSales = $sales->sum(fn (Document $document) => (float) $document->total) - (float) $returns;
        $rows = collect([
            ['item' => 'Net sales after returns', 'amount' => $netSales],
            ['item' => 'Gross profit after returns', 'amount' => $grossProfit - $returnProfit],
            ['item' => 'Sales returns', 'amount' => -(float) $returns],
        ]);

        return $this->table(['Metric', 'Amount'], $rows, ['net_sales' => $netSales, 'gross_profit' => $grossProfit - $returnProfit]);
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
        $rows = Product::query()->where('organization_id', $organizationId)->with('category:id,name')->with(['stocks' => fn ($query) => $query->where('branch_id', $branchId)])
            ->when($search, fn ($query) => $query->where(fn ($builder) => $builder->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")->orWhere('barcode', 'like', "%{$search}%")))
            ->orderBy('name')->get()->map(function (Product $product) {
                $quantity = (float) ($product->stocks->first()?->quantity_on_hand ?? 0);

                return ['product' => $product->name, 'sku' => $product->sku ?? '—', 'barcode' => $product->barcode ?? '—', 'category' => $product->category?->name ?? 'Uncategorized', 'quantity' => $quantity, 'unit' => $product->unit, 'cost_value' => $quantity * (float) $product->cost_price, 'sale_value' => $quantity * (float) $product->sale_price, 'reorder_level' => (float) $product->reorder_level, 'status' => $quantity <= (float) $product->reorder_level ? 'Low stock' : 'In stock'];
            });

        return $this->table(['Product', 'SKU', 'Barcode', 'Category', 'On hand', 'Unit', 'Cost value', 'Sale value', 'Reorder at', 'Status'], $rows, ['products' => $rows->count(), 'stock_value' => $rows->sum('cost_value'), 'low_stock' => $rows->where('status', 'Low stock')->count()]);
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

                return ['customer' => $contact->name, 'phone' => $contact->phone ?? '—', 'sales_count' => $sales->count(), 'sales' => $sales->sum(fn ($document) => (float) $document->total), 'paid' => $sales->sum(fn ($document) => (float) $document->amount_paid), 'due' => $sales->sum(fn ($document) => (float) $document->balance_due), 'credit_limit' => (float) $contact->credit_limit];
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
            ->map(fn (Document $document) => ['date' => $document->document_date, 'customer' => $document->contact?->name ?? '—', 'reference' => $document->number, 'type' => $document->type, 'debit' => $document->type === 'sale_return' ? 0 : (float) $document->total, 'credit' => $document->type === 'sale_return' ? (float) $document->total : (float) $document->amount_paid, 'balance' => (float) $document->balance_due]);

        return $this->table(['Date', 'Customer', 'Reference', 'Type', 'Debit', 'Credit', 'Open balance'], $rows, ['debit' => $rows->sum('debit'), 'credit' => $rows->sum('credit'), 'balance' => $rows->sum('balance')]);
    }

    private function customerDue(Builder $documents): array
    {
        $rows = (clone $documents)->whereIn('type', ['sale', 'resale'])->whereRaw('(total - amount_paid) > 0')->with('contact:id,name,phone')->orderByDesc('document_date')->get()
            ->map(fn (Document $document) => ['date' => $document->document_date, 'invoice' => $document->number, 'customer' => $document->contact?->name ?? '—', 'phone' => $document->contact?->phone ?? '—', 'total' => (float) $document->total, 'paid' => (float) $document->amount_paid, 'due' => (float) $document->balance_due, 'status' => $document->payment_status]);

        return $this->table(['Date', 'Invoice', 'Customer', 'Phone', 'Total', 'Paid', 'Due', 'Status'], $rows, ['invoices' => $rows->count(), 'due' => $rows->sum('due')]);
    }

    private function table(array $columns, $rows, array $summary): array
    {
        return ['columns' => $columns, 'rows' => $rows->values(), 'summary' => $summary];
    }
}
