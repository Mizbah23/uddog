<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\EcommerceOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductStock;
use App\Services\InventoryService;
use App\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EcommerceController extends Controller
{
    private function publicStore(Request $request): Organization
    {
        $organization = app(TenantContext::class)->organization();
        if (! $organization && ! config('tenancy.enabled')) {
            $suffix = '.'.strtolower((string) config('tenancy.base_domain'));
            $host = strtolower($request->getHost());
            abort_unless(str_ends_with($host, $suffix), 404);
            $label = substr($host, 0, -strlen($suffix));
            abort_if($label === '' || str_contains($label, '.'), 404);
            $organization = Organization::query()->where('subdomain', $label)->firstOrFail();
        }
        abort_unless($organization?->ecommerce_enabled && $organization->canUseWorkspace(), 404);

        return $organization;
    }

    private function ownerStore(Request $request): Organization
    {
        $organization = $request->user()->organization()->firstOrFail();
        abort_unless($organization->ecommerce_enabled, 404);

        return $organization;
    }

    private function storefrontBranch(Organization $organization): Branch
    {
        return Branch::query()->where('organization_id', $organization->id)->where('active', true)
            ->where('is_default', true)->firstOrFail();
    }

    private function available(Product $product, int $branchId): float
    {
        $sourceId = $product->stock_source_product_id ?: $product->id;
        $quantity = (float) ProductStock::query()->where('organization_id', $product->organization_id)
            ->where('branch_id', $branchId)->where('product_id', $sourceId)->value('quantity_on_hand');
        $ratio = $product->stock_source_product_id ? (float) $product->stock_source_quantity : 1;

        return $ratio > 0 ? floor($quantity / $ratio * 1000) / 1000 : 0;
    }

    public function show(Request $request): View
    {
        $organization = $this->publicStore($request);
        $this->storefrontBranch($organization);

        return view('ecommerce', ['storeName' => $organization->name]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $organization = $this->publicStore($request);
        $branch = $this->storefrontBranch($organization);
        $products = Product::query()->where('organization_id', $organization->id)->where('active', true)->where('ecommerce_visible', true)
            ->when(! $organization->shared_stock_sales, fn ($query) => $query->whereNull('stock_source_product_id'))
            ->with('category:id,name')->orderBy('name')->get();
        $stocks = ProductStock::query()->where('organization_id', $organization->id)->where('branch_id', $branch->id)
            ->whereIn('product_id', $products->map(fn (Product $product) => $product->stock_source_product_id ?: $product->id))
            ->pluck('quantity_on_hand', 'product_id');
        $items = $products->map(function (Product $product) use ($stocks): array {
            $stock = (float) ($stocks[$product->stock_source_product_id ?: $product->id] ?? 0);
            $ratio = $product->stock_source_product_id ? (float) $product->stock_source_quantity : 1;
            $minimum = $product->usesWholePieces() ? 1 : 0.001;

            return [
                'id' => $product->id, 'name' => $product->name, 'unit' => $product->unit,
                'category' => $product->category?->name ?? 'Everyday essentials',
                'price' => $product->is_gold_item ? null : $product->sale_price,
                'description' => $product->ecommerce_description, 'image_url' => $product->ecommerce_image_url,
                'in_stock' => $ratio > 0 && $stock / $ratio >= $minimum,
                'quantity_step' => $minimum, 'requires_quote' => $product->is_gold_item,
            ];
        });

        return response()->json(['store' => ['name' => $organization->name, 'phone' => $organization->phone ?: $branch->phone, 'address' => $organization->address ?: $branch->address], 'products' => $items])
            ->header('Cache-Control', 'no-store');
    }

    public function checkout(Request $request): JsonResponse
    {
        $organization = $this->publicStore($request);
        $branch = $this->storefrontBranch($organization);
        $data = $request->validate([
            'checkout_token' => ['required', 'uuid'], 'customer_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/\A\+?[0-9]{7,15}\z/D'], 'address' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'], 'expected_total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
        ]);
        $order = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($organization, $branch, $data): EcommerceOrder {
            DB::connection(app(TenantContext::class)->connectionName())->table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $existing = EcommerceOrder::query()->where('organization_id', $organization->id)->where('checkout_token', $data['checkout_token'])->first();
            if ($existing) {
                return $existing;
            }
            $items = [];
            $total = 0;
            foreach ($data['items'] as $item) {
                $product = Product::query()->where('organization_id', $organization->id)->where('active', true)
                    ->where('ecommerce_visible', true)->lockForUpdate()->find($item['product_id']);
                if (! $product || $product->is_gold_item || ($product->stock_source_product_id && ! $organization->shared_stock_sales)) {
                    throw ValidationException::withMessages(['items' => 'A product is no longer available online. Refresh the store and review your cart.']);
                }
                $quantity = (float) $item['quantity'];
                if (($product->usesWholePieces() && $quantity !== floor($quantity)) || $quantity > $this->available($product, $branch->id)) {
                    throw ValidationException::withMessages(['items' => "Please review the available quantity for {$product->name}."]);
                }
                $lineAmount = round($quantity * (float) $product->sale_price * 100);
                if (! is_finite($lineAmount) || $lineAmount < 0 || $lineAmount > 99999999999999) {
                    throw ValidationException::withMessages(['items' => 'The order total exceeds the supported range.']);
                }
                $lineTotal = (int) $lineAmount;
                $total += $lineTotal;
                if ($total > 99999999999999) {
                    throw ValidationException::withMessages(['items' => 'The order total exceeds the supported range.']);
                }
                $items[] = ['product_id' => $product->id, 'name' => $product->name, 'unit' => $product->unit, 'quantity' => $quantity, 'unit_price' => $product->sale_price, 'line_total' => number_format($lineTotal / 100, 2, '.', '')];
            }
            if ($total !== (int) round((float) $data['expected_total'] * 100)) {
                throw ValidationException::withMessages(['items' => 'Prices have changed. Refresh the store and review your cart before ordering.']);
            }

            return EcommerceOrder::create([
                'organization_id' => $organization->id, 'branch_id' => $branch->id, 'checkout_token' => $data['checkout_token'],
                'number' => 'WEB-'.strtoupper(Str::random(12)), 'customer_name' => $data['customer_name'], 'phone' => $data['phone'],
                'address' => $data['address'], 'notes' => $data['notes'] ?? null, 'items' => $items,
                'total' => number_format($total / 100, 2, '.', ''), 'status' => 'pending',
            ]);
        });

        return response()->json(['number' => $order->number, 'status' => $order->status], 201)->header('Cache-Control', 'no-store');
    }

    public function visibility(Request $request, int $product): JsonResponse
    {
        $organization = $this->ownerStore($request);
        $saved = Product::query()->where('organization_id', $organization->id)->findOrFail($product);
        $data = $request->validate(['ecommerce_visible' => ['required', 'boolean']]);
        $saved->update($data);

        return response()->json(['id' => $saved->id, 'ecommerce_visible' => $saved->ecommerce_visible]);
    }

    public function orders(Request $request): JsonResponse
    {
        $organization = $this->ownerStore($request);

        return response()->json(EcommerceOrder::query()->where('organization_id', $organization->id)->latest('id')->paginate(30));
    }

    public function process(Request $request, int $order, InventoryService $inventory): JsonResponse
    {
        $organization = $this->ownerStore($request);
        $data = $request->validate(['action' => ['required', Rule::in(['accept', 'cancel'])]]);
        $saved = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($order, $organization, $request, $inventory, $data): EcommerceOrder {
            $saved = EcommerceOrder::query()->where('organization_id', $organization->id)->lockForUpdate()->findOrFail($order);
            app(BranchAccess::class)->ensure($request->user(), $organization->id, $saved->branch_id);
            if ($saved->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'This order has already been processed.']);
            }
            if ($data['action'] === 'accept') {
                if (! $organization->setup()['sales']['allow_due_sales']) {
                    throw ValidationException::withMessages(['order' => 'Enable due sales in System Setup to accept cash-on-delivery orders.']);
                }
                $branch = Branch::query()->where('organization_id', $organization->id)->where('active', true)->findOrFail($saved->branch_id);
                foreach ($saved->items as $item) {
                    $product = Product::query()->where('organization_id', $organization->id)->where('active', true)->where('ecommerce_visible', true)->find($item['product_id']);
                    if (! $product || $product->is_gold_item || ($product->stock_source_product_id && ! $organization->shared_stock_sales)) {
                        throw ValidationException::withMessages(['order' => 'An ordered product is no longer available online.']);
                    }
                }
                $customer = Contact::query()->where('organization_id', $organization->id)->whereIn('type', ['customer', 'both'])->where('phone', $saved->phone)->first();
                $customer ??= Contact::create(['organization_id' => $organization->id, 'type' => 'customer', 'name' => $saved->customer_name, 'phone' => $saved->phone, 'address' => $saved->address]);
                $sale = $inventory->createDocument([
                    'type' => 'sale', 'contact_id' => $customer->id, 'document_date' => now('Asia/Dhaka')->toDateString(),
                    'payment_type' => 'due', 'payment_method' => 'credit', 'discount' => 0, 'tax' => 0,
                    'notes' => 'Online order '.$saved->number,
                    'items' => array_map(fn (array $item): array => ['product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'unit' => $item['unit'], 'unit_price' => $item['unit_price']], $saved->items),
                ], $request->user()->id, $organization->id, $branch->id);
                $saved->document_id = $sale->id;
            }
            $saved->status = $data['action'] === 'accept' ? 'accepted' : 'cancelled';
            $saved->processed_by = $request->user()->id;
            $saved->processed_at = now();
            $saved->save();

            return $saved;
        });

        return response()->json($saved);
    }
}
