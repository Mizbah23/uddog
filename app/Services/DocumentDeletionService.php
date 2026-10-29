<?php

namespace App\Services;

use App\Models\Document;
use App\Models\GoldJob;
use App\Models\Product;
use App\Models\ProductDamage;
use App\Models\ProductStock;
use App\Models\StockCheckItem;
use App\Models\StockMovement;
use App\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentDeletionService
{
    public function deletePayment(int $documentId, int $paymentId, int $organizationId, int $branchId): Document
    {
        return DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($documentId, $paymentId, $organizationId, $branchId): Document {
            $document = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->whereIn('type', ['purchase', 'sale', 'resale'])->lockForUpdate()->findOrFail($documentId);
            $purchase = $document->type === 'purchase';
            $relation = $purchase ? $document->purchasePayments() : $document->payments();
            $payment = $relation->lockForUpdate()->findOrFail($paymentId);
            $latestPayment = ($purchase ? $document->purchasePayments() : $document->payments())
                ->reorder('id', 'desc')->first();
            if ($latestPayment?->id !== $payment->id) {
                throw ValidationException::withMessages(['payment' => 'Delete the most recent payment first.']);
            }

            $amount = (int) round((float) $payment->amount * 100);
            $paid = (int) round((float) $document->amount_paid * 100);
            if ($amount <= 0 || $amount > $paid) {
                throw ValidationException::withMessages(['payment' => 'Payment totals need review before deletion.']);
            }
            $installmentId = $purchase ? $payment->purchase_installment_id : $payment->sale_installment_id;
            if ($installmentId) {
                $installment = ($purchase ? $document->purchaseInstallments() : $document->installments())
                    ->lockForUpdate()->findOrFail($installmentId);
                $installmentPaid = (int) round((float) $installment->amount_paid * 100);
                if ($amount > $installmentPaid) {
                    throw ValidationException::withMessages(['payment' => 'Installment totals need review before deletion.']);
                }
                $installment->update(['amount_paid' => number_format(($installmentPaid - $amount) / 100, 2, '.', '')]);
            } elseif ($document->payment_type === 'installment') {
                $firstInstallment = ($purchase ? $document->purchaseInstallments() : $document->installments())
                    ->orderBy('sequence')->lockForUpdate()->first();
                if (! $firstInstallment) {
                    throw ValidationException::withMessages(['payment' => 'Installment schedule needs review before deletion.']);
                }
                $firstInstallment->update(['amount' => number_format((int) round((float) $firstInstallment->amount * 100) / 100 + $amount / 100, 2, '.', '')]);
                $document->down_payment = number_format(max(0, (int) round((float) $document->down_payment * 100) - $amount) / 100, 2, '.', '');
            }

            $document->amount_paid = number_format(($paid - $amount) / 100, 2, '.', '');
            if ($document->payment_type === 'full') {
                $document->payment_type = 'due';
                $document->payment_method = 'credit';
            }
            $document->save();
            $payment->delete();

            return $document->fresh()->load('branch', 'contact', 'items.product', 'creator:id,name', 'installments', 'purchaseInstallments', 'payments.receiver', 'purchasePayments.receiver');
        });
    }

    public function delete(int $documentId, int $organizationId, int $branchId): void
    {
        DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($documentId, $organizationId, $branchId): void {
            $document = Document::query()->where('organization_id', $organizationId)->where('branch_id', $branchId)
                ->lockForUpdate()->findOrFail($documentId);
            if (! in_array($document->type, ['purchase', 'sale', 'resale'], true)) {
                throw ValidationException::withMessages(['document' => 'Only purchases and sales can be deleted here.']);
            }
            if ($document->corrections()->exists()) {
                throw ValidationException::withMessages(['document' => 'This sale has correction history and cannot be deleted. Use another correction to fix it.']);
            }
            if (Document::query()->where('organization_id', $organizationId)
                ->where(fn ($query) => $query->where('purchase_id', $document->id)->orWhere('sale_id', $document->id))->exists()) {
                throw ValidationException::withMessages(['document' => 'Delete related returns before deleting this transaction.']);
            }
            if (ProductDamage::query()->where('organization_id', $organizationId)->where('purchase_id', $document->id)->exists()
                || GoldJob::query()->where('organization_id', $organizationId)->where('document_id', $document->id)->exists()) {
                throw ValidationException::withMessages(['document' => 'Delete related damage or workshop records before deleting this transaction.']);
            }
            if ($document->purchasePayments()->exists()
                || $document->payments()->exists()
                || $document->purchaseInstallments()->where('amount_paid', '>', 0)->exists()
                || $document->installments()->where('amount_paid', '>', 0)->exists()) {
                throw ValidationException::withMessages(['document' => 'Delete all related payments before deleting this transaction.']);
            }

            $items = $document->items()->get();
            $movements = StockMovement::query()->where('document_id', $document->id)->orderBy('id')->get();
            if ($items->isEmpty() || $movements->count() !== $items->count()) {
                throw ValidationException::withMessages(['document' => 'The stock history for this transaction needs review before deletion.']);
            }
            $stockProductIds = $movements->pluck('product_id')->unique()->values()->all();
            if (StockCheckItem::query()->whereIn('product_id', $stockProductIds)
                ->whereHas('stockCheck', fn ($query) => $query->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId)->where('created_at', '>=', $document->created_at))->exists()) {
                throw ValidationException::withMessages(['document' => 'Resolve later stock checks before deleting this transaction.']);
            }

            foreach ($movements->groupBy('product_id') as $productId => $productMovements) {
                $product = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($productId);
                $stock = ProductStock::query()->where('branch_id', $branchId)->where('product_id', $productId)->lockForUpdate()->firstOrFail();
                $latestMovement = StockMovement::query()->where('product_id', $productId)->orderByDesc('id')->first();
                if (! $latestMovement || $latestMovement->id !== $productMovements->last()->id
                    || (float) $stock->quantity_on_hand !== (float) $productMovements->last()->balance_after) {
                    throw ValidationException::withMessages(['document' => 'Delete later stock transactions for these products first.']);
                }
                $change = $productMovements->sum(fn (StockMovement $movement) => (int) round((float) $movement->quantity_change * 1000));
                $branchBalance = (int) round((float) $stock->quantity_on_hand * 1000) - $change;
                $totalBalance = (int) round((float) $product->quantity_on_hand * 1000) - $change;
                if ($branchBalance < 0 || $totalBalance < 0) {
                    throw ValidationException::withMessages(['document' => 'Stock has already been used; delete later transactions first.']);
                }
                $updates = ['quantity_on_hand' => number_format($totalBalance / 1000, 3, '.', '')];
                if ($document->type === 'purchase') {
                    $purchaseItem = $items->firstWhere('product_id', $productId);
                    $updates['cost_price'] = $purchaseItem->cost_price;
                }
                $product->update($updates);
                $stock->update(['quantity_on_hand' => number_format($branchBalance / 1000, 3, '.', '')]);
            }

            StockMovement::query()->where('document_id', $document->id)->delete();
            $document->installments()->delete();
            $document->purchaseInstallments()->delete();
            $document->items()->delete();
            $document->delete();
        });
    }
}
