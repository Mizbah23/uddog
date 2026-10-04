<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $branchId = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, $request->integer('branch_id') ?: null)->id;

        return response()->json(Expense::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->with('creator:id,name')->latest('expense_date')->latest('id')->limit(500)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $organizationId = (int) Auth::user()->organization_id;
        $branchId = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, (int) $data['branch_id'])->id;
        unset($data['branch_id']);

        return response()->json(Expense::create([...$data, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'created_by' => Auth::id()])->load('creator:id,name'), 201);
    }

    public function update(Request $request, int $expense): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $record = Expense::query()->where('organization_id', $organizationId)->findOrFail($expense);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $record->branch_id);
        $data = $this->validated($request, false);
        unset($data['branch_id']);
        $record->update($data);

        return response()->json($record->fresh()->load('creator:id,name'));
    }

    public function destroy(int $expense): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $record = Expense::query()->where('organization_id', $organizationId)->findOrFail($expense);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $record->branch_id);
        $record->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $requireBranch = true): array
    {
        return $request->validate([
            'branch_id' => [$requireBranch ? 'required' : 'sometimes', 'integer'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'expense_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
