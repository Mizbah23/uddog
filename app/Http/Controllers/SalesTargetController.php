<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Document;
use App\Models\SalesTarget;
use App\Models\SupportImpersonation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class SalesTargetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $period = $this->period($request);
        $branchIds = app(BranchAccess::class)->ids(Auth::user(), $organizationId);
        $targets = SalesTarget::query()
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', $branchIds)
            ->whereDate('period', $period)
            ->with(['branch:id,name,code', 'user:id,name,email', 'creator:id,name'])
            ->orderBy('branch_id')
            ->orderBy('user_id')
            ->get();
        $actuals = $this->actualsForPeriod($organizationId, $period, $branchIds);

        $targets = $targets->map(function (SalesTarget $target) use ($actuals, $period) {
            $actual = $actuals[$target->branch_id.'|'.($target->user_id ?? 'all')]
                ?? 0;
            $amount = (float) $target->target_amount;

            return [
                ...$target->toArray(),
                'actual_amount' => number_format($actual, 2, '.', ''),
                'remaining_amount' => number_format(max(0, $amount - $actual), 2, '.', ''),
                'progress_percent' => $amount > 0 ? round(($actual / $amount) * 100, 1) : 0,
                'status' => $this->status($actual, $amount, $period),
            ];
        })->values();

        return response()->json([
            'targets' => $targets,
            'users' => User::query()->where('organization_id', $organizationId)->where('active', true)->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $data = $this->validated($request, $organizationId);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $data['branch_id']);
        $target = SalesTarget::query()->firstOrNew([
            'organization_id' => $organizationId,
            'branch_id' => $data['branch_id'],
            'user_id' => $data['user_id'] ?? null,
            'period' => $data['period'].'-01',
        ]);
        $target->fill([...$data, 'period' => $data['period'].'-01']);
        $target->created_by ??= Auth::id();
        $target->save();

        return response()->json($target->load(['branch:id,name,code', 'user:id,name,email', 'creator:id,name']), $target->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, int $salesTarget): JsonResponse
    {
        $organizationId = $this->organizationId();
        $target = SalesTarget::query()
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', app(BranchAccess::class)->ids(Auth::user(), $organizationId))
            ->findOrFail($salesTarget);
        $data = $this->validated($request, $organizationId);
        app(BranchAccess::class)->ensure(Auth::user(), $organizationId, $data['branch_id']);
        $target->update([...$data, 'period' => $data['period'].'-01']);

        return response()->json($target->fresh()->load(['branch:id,name,code', 'user:id,name,email', 'creator:id,name']));
    }

    public function destroy(int $salesTarget): JsonResponse
    {
        $organizationId = $this->organizationId();
        $target = SalesTarget::query()
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', app(BranchAccess::class)->ids(Auth::user(), $organizationId))
            ->findOrFail($salesTarget);
        $target->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, int $organizationId): array
    {
        return $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('organization_id', $organizationId)->where('active', true)],
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $organizationId)->where('active', true)],
            'period' => ['required', 'date_format:Y-m'],
            'target_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function period(Request $request): Carbon
    {
        $value = $request->validate(['period' => ['nullable', 'date_format:Y-m']])['period'] ?? now()->format('Y-m');

        return Carbon::createFromFormat('Y-m', $value)->startOfMonth();
    }

    private function actualsForPeriod(int $organizationId, Carbon $period, array $branchIds): array
    {
        $actuals = [];
        Document::query()
            ->selectRaw('branch_id, created_by, type, SUM(total) as total')
            ->where('organization_id', $organizationId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('document_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()])
            ->whereIn('type', ['sale', 'resale', 'sale_return'])
            ->groupBy('branch_id', 'created_by', 'type')
            ->get()
            ->each(function (Document $document) use (&$actuals) {
                $amount = (float) $document->total * ($document->type === 'sale_return' ? -1 : 1);
                $branchKey = $document->branch_id.'|all';
                $userKey = $document->branch_id.'|'.$document->created_by;
                $actuals[$branchKey] = ($actuals[$branchKey] ?? 0) + $amount;
                $actuals[$userKey] = ($actuals[$userKey] ?? 0) + $amount;
            });

        return $actuals;
    }

    private function status(float $actual, float $target, Carbon $period): string
    {
        if ($actual >= $target) {
            return 'achieved';
        }
        if ($period->isFuture()) {
            return 'upcoming';
        }
        if ($period->isPast() && ! $period->isSameMonth(now())) {
            return 'missed';
        }

        $expected = $target * (now()->day / now()->daysInMonth);

        return $actual >= $expected ? 'on_track' : 'behind';
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
