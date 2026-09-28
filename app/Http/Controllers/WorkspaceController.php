<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\SupportImpersonation;
use App\Models\User;
use App\Permission;
use App\Services\InventoryService;
use App\SubscriptionStatus;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function session(): JsonResponse
    {
        $user = Auth::user()?->load('organization');
        $impersonation = $user ? SupportImpersonation::activeFor(request()) : null;
        $abilities = $user ? collect(Permission::cases())
            ->filter(fn (Permission $permission) => $user->hasPermission($permission))
            ->map(fn (Permission $permission) => $permission->value)
            ->values()
            ->all() : [];

        return response()->json([
            'user' => $user,
            'setup_required' => ! User::query()->exists(),
            'permissions' => $user ? [
                'manage_clients' => $user->isSuperadmin(),
                'manage_users' => $user->hasRole(UserRole::Superadmin, UserRole::Admin),
                'use_workspace' => (bool) $impersonation
                    || (! $user->isSuperadmin() && $user->organization?->hasActiveSubscription() === true),
                'abilities' => $abilities,
            ] : null,
            'impersonation' => $impersonation ? [
                'id' => $impersonation->id,
                'impersonator' => $impersonation->impersonator->only(['id', 'name']),
                'owner' => $user->only(['id', 'name']),
                'organization' => $impersonation->organization->only(['id', 'name']),
                'started_at' => $impersonation->created_at,
            ] : null,
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
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt([...$credentials, 'active' => true])) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
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
        $documents = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId);

        return response()->json([
            'products' => $products->count(),
            'stock_value' => $products->sum(fn (Product $product) => (float) $product->quantity_on_hand * (float) $product->cost_price),
            'low_stock' => $products->filter(fn (Product $product) => (float) $product->quantity_on_hand <= (float) $product->reorder_level)->count(),
            'sales_total' => (clone $documents)->whereIn('type', ['sale', 'resale'])->sum('total') - (clone $documents)->where('type', 'sale_return')->sum('total'),
            'purchase_total' => (clone $documents)->where('type', 'purchase')->sum('total'),
            'recent_documents' => (clone $documents)->with('contact')->latest()->limit(6)->get(),
            'low_stock_products' => $products->filter(fn (Product $product) => (float) $product->quantity_on_hand <= (float) $product->reorder_level)->sortBy('quantity_on_hand')->take(6)->values(),
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
        if ($request->exists('sku')) {
            $sku = trim((string) $request->input('sku'));
            $request->merge(['sku' => $sku === '' ? null : $sku]);
        }
        if ($request->exists('barcode')) {
            $barcode = trim((string) $request->input('barcode'));
            $request->merge(['barcode' => $barcode === '' ? null : $barcode]);
        }

        $data = $request->validate([
            'sku' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('products')->where('organization_id', $organizationId)->ignore($savedProduct?->id)],
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
                Rule::unique('products')->where('organization_id', $organizationId)->ignore($savedProduct?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('organization_id', $organizationId)],
            'unit' => ['required', Rule::in(['pc', 'items', 'units', 'packs', 'boxes', 'cartons', 'sets', 'kg', 'g', 'L'])],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'active' => ['boolean'],
        ]);
        $data['cost_price'] = $data['cost_price'] ?? $savedProduct?->cost_price ?? 0;
        $data['sale_price'] = $data['sale_price'] ?? $savedProduct?->sale_price ?? 0;
        $saved = $savedProduct ? tap($savedProduct)->update($data) : Product::create([...$data, 'organization_id' => $organizationId]);

        return response()->json($saved, $savedProduct ? 200 : 201);
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
        ]);
        $saved = $savedContact ? tap($savedContact)->update($data) : Contact::create([...$data, 'organization_id' => $organizationId]);

        return response()->json($saved, $savedContact ? 200 : 201);
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
            ->with('branch', 'contact', 'items.product', 'purchase', 'sale', 'creator:id,name')
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
            'contact_id' => ['required', Rule::exists('contacts', 'id')->where('organization_id', $organizationId)],
            'purchase_id' => ['required_if:type,purchase_return', 'nullable', Rule::exists('documents', 'id')->where('organization_id', $organizationId)],
            'sale_id' => ['required_if:type,sale_return', 'nullable', Rule::exists('documents', 'id')->where('organization_id', $organizationId)],
            'document_date' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'payment_method' => ['required_if:sale_channel,pos', 'nullable', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer', 'credit'])],
            'amount_paid' => ['required_if:sale_channel,pos', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('organization_id', $organizationId), 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        if (($data['sale_channel'] ?? 'standard') === 'pos' && $data['type'] !== 'sale') {
            throw ValidationException::withMessages(['sale_channel' => 'POS checkout can only create a sale.']);
        }
        abort_unless(Auth::user()->hasPermission($this->permissionForDocumentType($data['type'])), 403, 'Your owner has not granted access to this transaction type.');
        $contact = Contact::query()->where('organization_id', $organizationId)->findOrFail($data['contact_id']);
        $expected = in_array($data['type'], ['purchase', 'purchase_return'], true) ? 'supplier' : 'customer';
        if (! in_array($contact->type, [$expected, 'both'], true)) {
            throw ValidationException::withMessages(['contact_id' => "Select a {$expected} contact."]);
        }

        return response()->json($inventory->createDocument($data, Auth::id(), $organizationId, $branchId), 201);
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
        $branch = Branch::query()->where('organization_id', $organizationId)
            ->when($request->input('branch_id'), fn ($query, $branchId) => $query->whereKey($branchId), fn ($query) => $query->where('is_default', true))
            ->where('active', true)
            ->first();
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Select an active branch.']);
        }

        return $branch->id;
    }

    private function productsForBranch(int $organizationId, int $branchId, ?string $search = null)
    {
        return Product::query()->where('organization_id', $organizationId)
            ->with('category:id,name,active')
            ->with(['stocks' => fn ($query) => $query->where('branch_id', $branchId)])
            ->when($search, fn ($query, $value) => $query->where(fn ($q) => $q->where('name', 'like', "%{$value}%")->orWhere('sku', 'like', "%{$value}%")->orWhere('barcode', 'like', "%{$value}%")->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$value}%"))))
            ->orderBy('name')->get()
            ->each(function (Product $product) {
                $product->setAttribute('quantity_on_hand', $product->stocks->first()?->quantity_on_hand ?? '0.000');
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
