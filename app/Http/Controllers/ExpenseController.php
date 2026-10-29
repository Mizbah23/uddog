<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Organization;
use App\Models\PayrollItem;
use App\Models\User;
use App\Services\CashBalanceService;
use App\TenantContext;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $branchExpenses = $this->visibleExpenses($organizationId)->where('branch_id', $branchId);
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
                ->merge($this->visibleExpenses($organizationId)->distinct()->pluck('category'))
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

        $expense = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($data, $organizationId, $branchId): Expense {
            Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail();
            $creator = Auth::user();
            $this->ensurePaidBalance($organizationId, $branchId, $creator, $data['amount']);
            $expense = Expense::create([...$data, 'organization_id' => $organizationId, 'branch_id' => $branchId, 'created_by' => $creator->id]);
            ExpenseCategory::query()->firstOrCreate(['organization_id' => $organizationId, 'name' => $data['category']]);

            return $expense;
        });

        return response()->json($expense->load('creator:id,name'), 201);
    }

    public function update(Request $request, int $expense): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $record = $this->visibleExpenses($organizationId)->findOrFail($expense);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $record->branch_id);
        abort_if(PayrollItem::query()->where('expense_id', $record->id)->exists(), 422, 'Payroll expenses must be managed from the staff module.');
        $data = $this->validated($request, false);
        unset($data['branch_id']);
        DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($record, $data, $organizationId): void {
            Branch::query()->whereKey($record->branch_id)->lockForUpdate()->firstOrFail();
            $creator = User::query()->where('organization_id', $organizationId)->find($record->created_by);
            if ($creator) {
                $this->ensurePaidBalance($organizationId, $record->branch_id, $creator, $data['amount'], $record->amount);
            }
            $record->update($data);
            ExpenseCategory::query()->firstOrCreate(['organization_id' => $organizationId, 'name' => $data['category']]);
        });

        return response()->json($record->fresh()->load('creator:id,name'));
    }

    public function destroy(int $expense): JsonResponse
    {
        $organizationId = (int) Auth::user()->organization_id;
        $record = $this->visibleExpenses($organizationId)->findOrFail($expense);
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

    private function visibleExpenses(int $organizationId): Builder
    {
        $user = Auth::user();

        return Expense::query()->where('organization_id', $organizationId)
            ->when($user->hasRole(UserRole::Manager, UserRole::Staff),
                fn (Builder $query) => $query->where('created_by', $user->id));
    }

    private function ensurePaidBalance(int $organizationId, int $branchId, User $creator, string|int|float $amount, string|int|float $previousAmount = 0): void
    {
        if (! $creator->hasRole(UserRole::Manager, UserRole::Staff)
            || ! Organization::query()->findOrFail($organizationId)->setup()['store']['allow_cash_handover']) {
            return;
        }

        $available = CashBalanceService::cents(app(CashBalanceService::class)->overview($organizationId, $branchId, $creator)['paid_balance'])
            + CashBalanceService::cents($previousAmount);
        if (CashBalanceService::cents($amount) > max(0, $available)) {
            throw ValidationException::withMessages([
                'amount' => 'Expense cannot exceed this user\'s available paid balance of ৳'.CashBalanceService::money(max(0, $available)).'.',
            ]);
        }
    }
}
