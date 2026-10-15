<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $organizationId = (int) Auth::user()->organization_id;
        $branchId = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, (int) ($filters['branch_id'] ?? 0) ?: null)->id;
        $branchExpenses = Expense::query()->where('organization_id', $organizationId)->where('branch_id', $branchId);
        $query = (clone $branchExpenses)
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('expense_date', '<=', $date))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['payment_method'] ?? null, fn ($query, $method) => $query->where('payment_method', $method))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $escaped = addcslashes($search, '%_\\');
                $query->where(fn ($query) => $query->where('description', 'like', "%{$escaped}%")
                    ->orWhere('notes', 'like', "%{$escaped}%"));
            });
        $summary = ['count' => (clone $query)->count(), 'total' => (clone $query)->sum('amount')];
        $page = $query->with('creator:id,name')->withExists('payrollItem')->orderByDesc('expense_date')->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => $page->items(),
            'summary' => $summary,
            'categories' => collect(['Rent', 'Utilities', 'Payroll', 'Transport', 'Marketing', 'Maintenance', 'Other'])
                ->merge(ExpenseCategory::query()->where('organization_id', $organizationId)->pluck('name'))
                ->merge(Expense::query()->where('organization_id', $organizationId)->distinct()->pluck('category'))
                ->unique(fn ($category) => mb_strtolower($category))
                ->values(),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $organizationId = (int) Auth::user()->organization_id;
        $branchId = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, (int) $data['branch_id'])->id;
        unset($data['branch_id']);

        $expense = Expense::create([...$data, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'created_by' => Auth::id()]);
        ExpenseCategory::query()->firstOrCreate(['organization_id' => $organizationId, 'name' => $data['category']]);

        return response()->json($expense->load('creator:id,name'), 201);
    }

    public function update(Request $request, int $expense): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $record = Expense::query()->where('organization_id', $organizationId)->findOrFail($expense);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $record->branch_id);
        abort_if(PayrollItem::query()->where('expense_id', $record->id)->exists(), 422, 'Payroll expenses must be managed from the staff module.');
        $data = $this->validated($request, false);
        unset($data['branch_id']);
        $record->update($data);
        ExpenseCategory::query()->firstOrCreate(['organization_id' => $organizationId, 'name' => $data['category']]);

        return response()->json($record->fresh()->load('creator:id,name'));
    }

    public function destroy(int $expense): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $record = Expense::query()->where('organization_id', $organizationId)->findOrFail($expense);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $record->branch_id);
        abort_if(PayrollItem::query()->where('expense_id', $record->id)->exists(), 422, 'Payroll expenses must be managed from the staff module.');
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
