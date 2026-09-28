<?php

namespace App\Http\Controllers;

use App\Http\Requests\RenewClientRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Organization;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json($this->companyQuery()
            ->whereDoesntHave('users', fn (Builder $query) => $query
                ->where('role', UserRole::Superadmin->value))
            ->orderBy('name')
            ->get());
    }

    public function show(int $client): JsonResponse
    {
        $organization = $this->companyQuery()
            ->with([
                'users:id,organization_id,name,email,role,active,created_at',
                'supportImpersonations' => fn ($query) => $query
                    ->with('impersonator:id,name')
                    ->latest()
                    ->limit(20),
            ])
            ->findOrFail($client);

        return response()->json($organization);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $data = $request->validated();
        $organization = DB::transaction(function () use ($data) {
            $organization = Organization::create(collect($data)->except(['owner_name', 'owner_email', 'owner_password'])->all());
            User::create([
                'organization_id' => $organization->id,
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $data['owner_password'],
                'role' => UserRole::Admin,
                'active' => true,
            ]);

            return $organization;
        });

        return response()->json($this->companyQuery()->findOrFail($organization->id), 201);
    }

    public function update(UpdateClientRequest $request, int $client): JsonResponse
    {
        $data = $request->validated();
        $organization = DB::transaction(function () use ($client, $data) {
            $organization = Organization::query()->findOrFail($client);
            $organization->update(collect($data)->except([
                'owner_name', 'owner_email', 'owner_password', 'owner_active',
            ])->all());

            $owner = $organization->admins()->oldest()->first();
            $ownerData = [
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'active' => $data['owner_active'],
            ];
            if (! empty($data['owner_password'])) {
                $ownerData['password'] = $data['owner_password'];
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

        return response()->json($this->companyQuery()->findOrFail($organization->id));
    }

    public function renew(RenewClientRequest $request, int $client): JsonResponse
    {
        $organization = Organization::query()->findOrFail($client);
        $currentEnd = $organization->subscription_ends_at;
        $startsFrom = $currentEnd && $currentEnd->greaterThanOrEqualTo(today())
            ? $currentEnd->copy()
            : today();

        $organization->update([
            'subscription_status' => 'active',
            'subscription_ends_at' => $startsFrom->addMonthsNoOverflow($request->integer('months')),
        ]);

        return response()->json($this->companyQuery()->findOrFail($organization->id));
    }

    private function companyQuery(): Builder
    {
        return Organization::query()
            ->with('admins:id,organization_id,name,email,active,created_at')
            ->withCount(['users', 'products', 'contacts', 'documents'])
            ->withMax('supportImpersonations', 'created_at');
    }
}
