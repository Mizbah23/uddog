<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\Document;
use App\Models\Expense;
use App\Models\PurchasePayment;
use App\Models\SalePayment;
use App\Models\User;
use App\UserRole;

class CashBalanceService
{
    /** @return array<string, mixed> */
    public function overview(int $organizationId, int $branchId, User $viewer): array
    {
        $owner = $viewer->hasRole(UserRole::Admin);
        $userId = $viewer->id;
        $receipts = $this->totals(SalePayment::query(), $organizationId, $branchId, $owner ? null : $userId);
        $supplierRefunds = $this->totals(Document::query()->where('type', 'purchase_return'), $organizationId, $branchId, $owner ? null : $userId, 'total');
        $supplierPayments = $this->totals(PurchasePayment::query(), $organizationId, $branchId, $owner ? null : $userId);
        $expenses = $this->totals(Expense::query(), $organizationId, $branchId, $owner ? null : $userId);
        $customerRefunds = $this->totals(Document::query()->where('type', 'sale_return'), $organizationId, $branchId, $owner ? null : $userId, 'total');
        $movements = CashMovement::query()->where('organization_id', $organizationId)->where('branch_id', $branchId);
        $outgoing = (clone $movements)->when(! $owner, fn ($query) => $query->where('from_user_id', $userId))
            ->selectRaw('from_user_id, type, SUM(amount) as total')->groupBy('from_user_id', 'type')->get();
        $incoming = (clone $movements)->whereNotNull('to_user_id')
            ->when(! $owner, fn ($query) => $query->where('to_user_id', $userId))
            ->selectRaw('to_user_id, SUM(amount) as total')->groupBy('to_user_id')->get();

        $balances = [];
        foreach ([$receipts, $supplierRefunds] as $payments) {
            foreach ($payments as $id => $amount) {
                $balances[$id] = ($balances[$id] ?? 0) + $amount;
            }
        }
        foreach ([$supplierPayments, $expenses, $customerRefunds] as $payments) {
            foreach ($payments as $id => $amount) {
                $balances[$id] = ($balances[$id] ?? 0) - $amount;
            }
        }
        $transferred = 0;
        $withdrawn = 0;
        foreach ($outgoing as $movement) {
            $id = (int) $movement->from_user_id;
            $amount = self::cents($movement->total);
            $balances[$id] = ($balances[$id] ?? 0) - $amount;
            if ($id === $userId) {
                $movement->type === 'transfer' ? $transferred += $amount : $withdrawn += $amount;
            }
        }
        $received = 0;
        foreach ($incoming as $movement) {
            $id = (int) $movement->to_user_id;
            $amount = self::cents($movement->total);
            $balances[$id] = ($balances[$id] ?? 0) + $amount;
            if ($id === $userId) {
                $received += $amount;
            }
        }

        return [
            'on_hand' => self::money($balances[$userId] ?? 0),
            'cash_received' => self::money(($receipts[$userId] ?? 0) + ($supplierRefunds[$userId] ?? 0)),
            'cash_paid' => self::money(($supplierPayments[$userId] ?? 0) + ($expenses[$userId] ?? 0) + ($customerRefunds[$userId] ?? 0)),
            'transferred' => self::money($transferred),
            'received_transfers' => self::money($received),
            'withdrawn' => self::money($withdrawn),
            'staff_balances' => $owner ? User::query()->where('organization_id', $organizationId)
                ->whereIn('role', [UserRole::Manager->value, UserRole::Staff->value])
                ->orderBy('name')->get(['id', 'name'])->map(fn (User $user) => [
                    'id' => $user->id, 'name' => $user->name, 'on_hand' => self::money($balances[$user->id] ?? 0),
                ])->all() : [],
            'recent_movements' => (clone $movements)->when(! $owner, fn ($query) => $query->where('from_user_id', $userId))
                ->with('sender:id,name', 'recipient:id,name')->latest()->limit(10)->get(),
        ];
    }

    /** @return array<int, int> */
    private function totals($query, int $organizationId, int $branchId, ?int $userId, string $amountColumn = 'amount'): array
    {
        return $query->where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->where('payment_method', 'cash')
            ->when($userId, fn ($query) => $query->where('created_by', $userId))
            ->selectRaw("created_by, SUM({$amountColumn}) as total")->groupBy('created_by')->get()
            ->mapWithKeys(fn ($item) => [(int) $item->created_by => self::cents($item->total)])->all();
    }

    public static function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
