<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\StaffMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer']]);
        $organizationId = (int) Auth::user()->organization_id;
        $branch = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, (int) $data['branch_id']);

        return response()->json(StaffMember::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branch->id)
            ->orderBy('name')
            ->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $organizationId = (int) Auth::user()->organization_id;
        $branch = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, (int) $data['branch_id']);
        $staff = StaffMember::create([...$data, 'organization_id' => $organizationId, 'branch_id' => $branch->id]);

        return response()->json($staff, 201);
    }

    public function update(Request $request, int $staffMember): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $staff = StaffMember::query()->where('organization_id', $organizationId)->findOrFail($staffMember);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $staff->branch_id);
        $data = $this->validated($request);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, (int) $data['branch_id']);
        $staff->update($data);

        return response()->json($staff->fresh());
    }

    public function destroy(int $staffMember): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $staff = StaffMember::query()->where('organization_id', $organizationId)->findOrFail($staffMember);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $staff->branch_id);
        $staff->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'job_title' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'monthly_salary' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'start_date' => ['required', 'date'],
            'active' => ['required', 'boolean'],
        ]);
    }
}
