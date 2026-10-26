<?php

namespace App\Http\Controllers;

use App\BranchAccess;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Organization;
use App\Models\User;
use App\Services\CashBalanceService;
use App\TenantContext;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashMovementController extends Controller
{
    public function transfer(Request $request, CashBalanceService $balances): JsonResponse
    {
        return $this->record($request, $balances, 'transfer');
    }

    public function withdraw(Request $request, CashBalanceService $balances): JsonResponse
    {
        return $this->record($request, $balances, 'withdrawal');
    }

    private function record(Request $request, CashBalanceService $balances, string $type): JsonResponse
    {
        $user = $request->user();
        $organization = Organization::query()->findOrFail($user->organization_id);
        abort_unless($organization->setup()['store']['allow_cash_handover'], 403, 'Cash handover is disabled in System Setup.');
        abort_unless($type === 'transfer' ? $user->hasRole(UserRole::Manager, UserRole::Staff) : $user->hasRole(UserRole::Admin), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'amount' => [$type === 'withdrawal' ? 'required' : 'prohibited', 'numeric', 'gt:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $branch = app(BranchAccess::class)->activeBranch($user, $organization->id, (int) $data['branch_id']);
        $recipient = $type === 'transfer' ? User::query()->where('organization_id', $organization->id)
            ->where('role', UserRole::Admin->value)->where('active', true)->orderBy('id')->first() : null;
        if ($type === 'transfer' && ! $recipient) {
            throw ValidationException::withMessages(['cash' => 'An active store owner is required to receive cash.']);
        }

        $movement = DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($balances, $branch, $data, $organization, $recipient, $type, $user): CashMovement {
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $available = CashBalanceService::cents($balances->overview($organization->id, $branch->id, $user)['on_hand']);
            $amount = $type === 'transfer' ? $available : CashBalanceService::cents($data['amount']);
            if ($amount <= 0 || $amount > $available) {
                throw ValidationException::withMessages(['amount' => 'The amount must be positive and cannot exceed your available customer payment balance.']);
            }

            return CashMovement::create([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'from_user_id' => $user->id,
                'to_user_id' => $recipient?->id,
                'type' => $type,
                'amount' => CashBalanceService::money($amount),
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json([
            'movement' => $movement->load('sender:id,name', 'recipient:id,name'),
            'cash' => $balances->overview($organization->id, $branch->id, $user),
        ], 201);
    }
}
