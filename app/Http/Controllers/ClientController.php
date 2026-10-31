<?php

namespace App\Http\Controllers;

use App\Http\Requests\RenewClientRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\DefaultCustomer;
use App\Services\PackageLimitService;
use App\Services\TenantProvisioner;
use App\TenantContext;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        $companies = $this->companyQuery()
            ->whereDoesntHave('users', fn (Builder $query) => $query
                ->where('role', UserRole::Superadmin->value))
            ->orderBy('name')
            ->get();

        if (config('tenancy.enabled')) {
            $companies->each(fn (Organization $company) => $this->loadTenantCounts($company));
        }

        $companies->each(fn (Organization $company) => $this->addProvisioningDetails($company));

        return response()->json($companies);
    }

    public function show(int $client): JsonResponse
    {
        $organization = $this->companyQuery()
            ->with([
                'users:id,organization_id,name,email,mobile,role,active,access_paused,created_at',
                'supportImpersonations' => fn ($query) => $query->with('impersonator:id,name')->latest()->limit(20),
            ])
            ->findOrFail($client);

        if (config('tenancy.enabled')) {
            $this->loadTenantCounts($organization);
        }

        $this->addProvisioningDetails($organization);

        if ($organization->tenant_database || ! config('tenancy.enabled')) {
            app(TenantContext::class)->run($organization, function () use ($organization): void {
                $organization->load([
                    'branches' => fn ($query) => $query->withCount('documents')->withSum('stocks', 'quantity_on_hand')
                        ->orderByDesc('is_default')->orderBy('name'),
                ]);
                if (config('tenancy.enabled')) {
                    $organization->setRelation('branches', $organization->branches->map(fn ($branch) => $branch->toArray()));
                }
            });
        } else {
            $organization->setRelation('branches', collect());
        }

        return response()->json($organization);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $data = $request->validated();
        $organization = DB::transaction(function () use ($data) {
            $organization = Organization::create(collect($data)->except(['owner_name', 'owner_email', 'owner_mobile', 'owner_password'])->all());
            User::create([
                'organization_id' => $organization->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'] ?? null,
                'mobile' => $data['owner_mobile'] ?? null,
                'password' => $data['owner_password'],
                'role' => UserRole::Admin,
                'active' => true,
            ]);
            app(DefaultCustomer::class)->ensure(DB::connection(config('tenancy.central_connection')), $organization);

            return $organization;
        });

        if (config('tenancy.enabled') && config('tenancy.provisioning_mode') !== 'cpanel') {
            app(TenantProvisioner::class)->provision($organization);
        }

        $created = $this->companyQuery()->findOrFail($organization->id);
        $this->addProvisioningDetails($created);

        return response()->json($created, 201);
    }

    public function provision(Request $request, int $client, TenantProvisioner $provisioner): JsonResponse
    {
        abort_unless(config('tenancy.enabled') && config('tenancy.provisioning_mode') === 'cpanel', 404);

        $organization = Organization::query()->findOrFail($client);
        abort_if($organization->users()->where('role', UserRole::Superadmin->value)->exists(), 403);
        $request->validate([
            'database' => ['required', 'string', 'in:'.config('tenancy.database_prefix').$organization->id],
        ]);
        try {
            $provisioner->provisionExisting($organization);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['database' => $exception->getMessage()]);
        }

        $provisioned = $this->companyQuery()->findOrFail($client);
        $this->loadTenantCounts($provisioned);
        $this->addProvisioningDetails($provisioned);

        return response()->json($provisioned);
    }

    public function update(UpdateClientRequest $request, int $client): JsonResponse
    {
        $data = $request->validated();
        $organization = DB::transaction(function () use ($client, $data) {
            $organization = Organization::query()->findOrFail($client);
            $organization->update(collect($data)->except([
                'owner_name', 'owner_email', 'owner_mobile', 'owner_password', 'owner_active',
            ])->all());

            $owner = $organization->admins()->oldest()->first();
            $ownerData = [
                'name' => $data['owner_name'],
                'email' => $data['owner_email'] ?? null,
                'mobile' => $data['owner_mobile'] ?? null,
                'active' => $data['owner_active'],
            ];
            if (! empty($data['owner_password'])) {
                $ownerData['password'] = $data['owner_password'];
            }

            if ($ownerData['active']) {
                app(PackageLimitService::class)->assertUserCapacity($organization->id, $owner?->id);
            }
            if ($owner) {
                $owner->update($ownerData);
            } else {
                User::create([
                    ...$ownerData,
                    'organization_id' => $organization->id,
                    'password' => $data['owner_password'],
                    'role' => UserRole::Admin,
                ]);
            }

            return $organization;
        });

        app(TenantProvisioner::class)->syncOrganizationShadow($organization);
        if (config('tenancy.enabled')) {
            app(TenantProvisioner::class)->syncUserShadow($organization->admins()->oldest()->firstOrFail());
        }

        return response()->json($this->companyQuery()->findOrFail($organization->id));
    }

    public function renew(RenewClientRequest $request, int $client): JsonResponse
    {
        $organization = Organization::query()->findOrFail($client);
        $currentEnd = $organization->subscription_ends_at;
        $startsFrom = $currentEnd && $currentEnd->toDateString() >= today('Asia/Dhaka')->toDateString()
            ? $currentEnd->copy()
            : today('Asia/Dhaka');

        $organization->update([
            'subscription_status' => 'active',
            'subscription_ends_at' => $startsFrom->addMonthsNoOverflow($request->integer('months')),
        ]);

        app(TenantProvisioner::class)->syncOrganizationShadow($organization);

        return response()->json($this->companyQuery()->findOrFail($organization->id));
    }

    public function updateAccess(Request $request, int $client): JsonResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $organization = $this->companyQuery()
            ->whereDoesntHave('users', fn (Builder $query) => $query->where('role', UserRole::Superadmin->value))
            ->findOrFail($client);
        $organization->update(['access_paused' => $data['paused']]);
        app(TenantProvisioner::class)->syncOrganizationShadow($organization);

        return response()->json($this->companyQuery()->findOrFail($organization->id));
    }

    private function companyQuery(): Builder
    {
        $query = Organization::query()
            ->with('admins:id,organization_id,name,email,mobile,active,created_at')
            ->withCount('users')
            ->withMax('supportImpersonations', 'created_at');

        return config('tenancy.enabled') ? $query : $query->withCount(['products', 'contacts', 'documents']);
    }

    private function loadTenantCounts(Organization $organization): void
    {
        if (! $organization->tenant_database) {
            $organization->setAttribute('products_count', 0);
            $organization->setAttribute('contacts_count', 0);
            $organization->setAttribute('documents_count', 0);

            return;
        }

        app(TenantContext::class)->run($organization, function () use ($organization): void {
            $organization->setAttribute('products_count', Product::query()->where('organization_id', $organization->id)->count());
            $organization->setAttribute('contacts_count', Contact::query()->where('organization_id', $organization->id)->count());
            $organization->setAttribute('documents_count', Document::query()->where('organization_id', $organization->id)->count());
        });
    }

    private function addProvisioningDetails(Organization $organization): void
    {
        if (config('tenancy.enabled') && config('tenancy.provisioning_mode') === 'cpanel') {
            $organization->setAttribute('expected_tenant_database', config('tenancy.database_prefix').$organization->id);
        }
    }
}
