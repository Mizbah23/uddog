<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Expense;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\StaffMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer']]);
        $organizationId = (int) Auth::user()->organization_id;
        $branch = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, (int) $data['branch_id']);

        return response()->json(PayrollRun::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branch->id)
            ->with(['items', 'creator:id,name'])
            ->orderByDesc('period')
            ->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'period' => ['required', 'date_format:Y-m'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'mobile_banking', 'bank_transfer'])],
        ]);
        $organizationId = (int) Auth::user()->organization_id;
        $branch = app(BranchAccess::class)->activeBranch(Auth::user(), $organizationId, (int) $data['branch_id']);
        $period = CarbonImmutable::createFromFormat('!Y-m', $data['period']);

        $connection = (new PayrollRun)->getConnection();
        try {
            $run = $connection->transaction(function () use ($data, $organizationId, $branch, $period) {
                if (PayrollRun::query()->where('organization_id', $organizationId)->where('branch_id', $branch->id)->where('period', $data['period'])->exists()) {
                    throw ValidationException::withMessages(['period' => 'Salary has already been generated for this branch and month.']);
                }

                $staff = StaffMember::query()
                    ->where('organization_id', $organizationId)
                    ->where('branch_id', $branch->id)
                    ->where('active', true)
                    ->whereDate('start_date', '<=', $period->endOfMonth()->toDateString())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($staff->isEmpty()) {
                    throw ValidationException::withMessages(['period' => 'No active staff are eligible for this branch and month.']);
                }

                $totalCents = $staff->sum(fn (StaffMember $member) => (int) round(((float) $member->monthly_salary) * 100));
                $run = PayrollRun::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $branch->id,
                    'period' => $data['period'],
                    'payment_date' => $data['payment_date'],
                    'payment_method' => $data['payment_method'],
                    'staff_count' => $staff->count(),
                    'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                    'created_by' => Auth::id(),
                ]);

                foreach ($staff as $member) {
                    $expense = Expense::create([
                        'organization_id' => $organizationId,
                        'branch_id' => $branch->id,
                        'category' => 'Payroll',
                        'description' => "Salary for {$period->format('F Y')} — {$member->name}",
                        'amount' => $member->monthly_salary,
                        'expense_date' => $data['payment_date'],
                        'payment_method' => $data['payment_method'],
                        'created_by' => Auth::id(),
                    ]);
                    PayrollItem::create([
                        'payroll_run_id' => $run->id,
                        'staff_member_id' => $member->id,
                        'expense_id' => $expense->id,
                        'staff_name' => $member->name,
                        'job_title' => $member->job_title,
                        'amount' => $member->monthly_salary,
                    ]);
                }

                return $run;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (PayrollRun::query()->where('organization_id', $organizationId)->where('branch_id', $branch->id)->where('period', $data['period'])->exists()) {
                throw ValidationException::withMessages(['period' => 'Salary has already been generated for this branch and month.']);
            }

            throw $exception;
        }

        return response()->json($run->load(['items', 'creator:id,name']), 201);
    }
}
