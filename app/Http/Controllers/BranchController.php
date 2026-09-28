<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\SupportImpersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Branch::query()->where('organization_id', $this->organizationId())
            ->withCount('documents')->withSum('stocks', 'quantity_on_hand')
            ->orderByDesc('is_default')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $data = $this->validated($request, $organizationId);
        if ($data['is_default'] && ! $data['active']) {
            throw ValidationException::withMessages(['active' => 'The default branch must remain active.']);
        }
        $branch = DB::transaction(function () use ($data, $organizationId) {
            if ($data['is_default']) {
                Branch::where('organization_id', $organizationId)->update(['is_default' => false]);
            }

            return Branch::create([...$data, 'organization_id' => $organizationId]);
        });

        return response()->json($branch->loadCount('documents')->loadSum('stocks', 'quantity_on_hand'), 201);
    }

    public function update(Request $request, int $branch): JsonResponse
    {
        $organizationId = $this->organizationId();
        $saved = Branch::query()->where('organization_id', $organizationId)->findOrFail($branch);
        $data = $this->validated($request, $organizationId, $saved->id);
        if ($data['is_default'] && ! $data['active']) {
            throw ValidationException::withMessages(['active' => 'The default branch must remain active.']);
        }
        if ($saved->is_default && ! $data['is_default']) {
            throw ValidationException::withMessages(['is_default' => 'Assign another default branch before changing this one.']);
        }
        DB::transaction(function () use ($saved, $data, $organizationId) {
            if ($data['is_default']) {
                Branch::where('organization_id', $organizationId)->whereKeyNot($saved->id)->update(['is_default' => false]);
            }
            $saved->update($data);
        });

        return response()->json($saved->fresh()->loadCount('documents')->loadSum('stocks', 'quantity_on_hand'));
    }

    public function destroy(int $branch): JsonResponse
    {
        $saved = Branch::query()->where('organization_id', $this->organizationId())->withCount('documents')->withSum('stocks', 'quantity_on_hand')->findOrFail($branch);
        if ($saved->is_default || $saved->documents_count > 0 || (float) $saved->stocks_sum_quantity_on_hand !== 0.0) {
            throw ValidationException::withMessages(['branch' => 'Default branches and branches with transactions or stock cannot be deleted.']);
        }
        $saved->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, int $organizationId, ?int $branchId = null): array
    {
        if ($request->exists('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'alpha_dash', 'max:30', Rule::unique('branches')->where('organization_id', $organizationId)->ignore($branchId)],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);
    }

    private function organizationId(): int
    {
        if (Auth::user()?->isSuperadmin() && ! SupportImpersonation::activeFor(request())) {
            abort(403, 'Open a company through View as owner before using its workspace.');
        }
        abort_unless(Auth::user()?->organization_id, 403, 'Select a client account before using the inventory workspace.');

        return Auth::user()->organization_id;
    }
}
