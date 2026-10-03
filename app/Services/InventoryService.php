<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SaleInstallment;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function createDocument(array $data, int $userId, int $organizationId, int $branchId): Document
    {
        return DB::transaction(function () use ($data, $userId, $organizationId, $branchId) {
            $type = $data['type'];
            $purchase = null;
            $sale = null;
            if ($type === 'purchase_return') {
                $purchase = Document::query()->where('organization_id', $organizationId)->lockForUpdate()->find($data['purchase_id']);
                if (! $purchase || $purchase->type !== 'purchase' || (int) $purchase->branch_id !== $branchId || (int) $purchase->contact_id !== (int) $data['contact_id']) {
                    throw ValidationException::withMessages(['purchase_id' => 'Select a purchase for the same supplier.']);
                }
            }
            if ($type === 'sale_return') {
                $sale = Document::query()->where('organization_id', $organizationId)->lockForUpdate()->find($data['sale_id']);
                if (! $sale || ! in_array($sale->type, ['sale', 'resale'], true) || (int) $sale->branch_id !== $branchId || (int) $sale->contact_id !== (int) $data['contact_id']) {
                    throw ValidationException::withMessages(['sale_id' => 'Select a sale for the same customer.']);
                }
            }

            $items = [];
            $subtotalCents = 0;
            foreach ($data['items'] as $item) {
                $product = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->find($item['product_id']);
                if (! $product || (! $product->active && ! in_array($type, ['purchase_return', 'sale_return'], true))) {
                    throw ValidationException::withMessages(['items' => 'Every product must be active.']);
                }
                $quantity = self::scaled($item['quantity'], 3);
                $unitCents = self::scaled($item['unit_price'], 2);
                $sourceCostCents = null;
                if ($sale) {
                    $soldItem = $sale->items()->where('product_id', $product->id)->first();
                    if (! $soldItem) {
                        throw ValidationException::withMessages(['items' => "{$product->name} was not included in the selected sale."]);
                    }
                    $unitCents = self::scaled($soldItem->unit_price, 2);
                    $sourceCostCents = self::scaled($soldItem->cost_price, 2);
                }
                $lineCents = intdiv($quantity * $unitCents + 500, 1000);
                $subtotalCents += $lineCents;
                $items[] = [$product, $quantity, $unitCents, $lineCents, $sourceCostCents];
            }

            $discountCents = $type === 'sale_return' ? 0 : self::scaled($data['discount'] ?? 0, 2);
            $taxCents = $type === 'sale_return' ? 0 : self::scaled($data['tax'] ?? 0, 2);
            if ($discountCents > $subtotalCents) {
                throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the subtotal.']);
            }
            $totalCents = $subtotalCents - $discountCents + $taxCents;
            $saleChannel = $data['sale_channel'] ?? 'standard';
            $paymentTerms = $this->paymentTerms($data, $type, $saleChannel, $totalCents);

            $document = Document::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'number' => 'TMP-'.bin2hex(random_bytes(12)),
                'type' => $type,
                'sale_channel' => $saleChannel,
                'contact_id' => $data['contact_id'],
                'purchase_id' => $purchase?->id,
                'sale_id' => $sale?->id,
                'document_date' => $data['document_date'],
                'subtotal' => self::money($subtotalCents),
                'discount' => self::money($discountCents),
                'tax' => self::money($taxCents),
                'total' => self::money($totalCents),
                'payment_method' => $paymentTerms['payment_method'],
                'payment_type' => $paymentTerms['payment_type'],
                'amount_paid' => self::money($paymentTerms['initial_payment']),
                'down_payment' => self::money($paymentTerms['down_payment']),
                'installment_count' => $paymentTerms['installment_count'],
                'installment_frequency' => $paymentTerms['installment_frequency'],
                'first_installment_date' => $paymentTerms['first_installment_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $prefix = ['purchase' => 'PUR', 'sale' => 'SAL', 'resale' => 'RSL', 'purchase_return' => 'PRT', 'sale_return' => 'SRT'][$type];
            $document->update(['number' => $prefix.'-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT)]);

            if ($paymentTerms['initial_payment'] > 0) {
                $document->payments()->create([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'amount' => self::money($paymentTerms['initial_payment']),
                    'payment_method' => $paymentTerms['payment_method'],
                    'kind' => $paymentTerms['payment_type'] === 'installment' ? 'down_payment' : 'initial',
                    'payment_date' => $data['document_date'],
                    'created_by' => $userId,
                ]);
            }
            if ($paymentTerms['payment_type'] === 'installment') {
                $this->createInstallments($document, $organizationId, $branchId, $paymentTerms);
            }

            foreach ($items as [$product, $quantity, $unitCents, $lineCents, $sourceCostCents]) {
                if ($purchase) {
                    $purchased = self::scaled($purchase->items()->where('product_id', $product->id)->sum('quantity'), 3);
                    $returned = self::scaled(DB::table('document_items')
                        ->join('documents', 'documents.id', '=', 'document_items.document_id')
                        ->where('documents.purchase_id', $purchase->id)
                        ->where('document_items.product_id', $product->id)
                        ->sum('document_items.quantity'), 3);
                    if ($quantity > $purchased - $returned) {
                        throw ValidationException::withMessages(['items' => "Return quantity exceeds the remaining purchased quantity for {$product->name}."]);
                    }
                }
                if ($sale) {
                    $sold = self::scaled($sale->items()->where('product_id', $product->id)->sum('quantity'), 3);
                    $returned = self::scaled(DB::table('document_items')
                        ->join('documents', 'documents.id', '=', 'document_items.document_id')
                        ->where('documents.sale_id', $sale->id)
                        ->where('document_items.product_id', $product->id)
                        ->sum('document_items.quantity'), 3);
                    if ($quantity > $sold - $returned) {
                        throw ValidationException::withMessages(['items' => "Return quantity exceeds the remaining sold quantity for {$product->name}."]);
                    }
                }

                ProductStock::query()->firstOrCreate([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                ], ['quantity_on_hand' => 0]);
                $branchStock = ProductStock::query()->where('branch_id', $branchId)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
                $branchQuantity = self::scaled($branchStock->quantity_on_hand, 3);
                $aggregateQuantity = self::scaled($product->quantity_on_hand, 3);
                $currentCostCents = self::scaled($product->cost_price, 2);
                $capturedCostCents = $sourceCostCents ?? $currentCostCents;
                $capturedCost = self::money($capturedCostCents);
                $change = in_array($type, ['purchase', 'sale_return'], true) ? $quantity : -$quantity;
                $branchBalance = $branchQuantity + $change;
                $aggregateBalance = $aggregateQuantity + $change;
                if ($branchBalance < 0) {
                    throw ValidationException::withMessages(['items' => "Insufficient stock for {$product->name}."]);
                }
                $productUpdate = ['quantity_on_hand' => self::quantity($aggregateBalance)];
                if ($type === 'purchase') {
                    $averageCostCents = intdiv(($aggregateQuantity * $currentCostCents) + ($quantity * $unitCents) + intdiv($aggregateBalance, 2), $aggregateBalance);
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                } elseif ($type === 'purchase_return') {
                    $remainingValue = ($aggregateQuantity * $currentCostCents) - ($quantity * $unitCents);
                    $averageCostCents = $aggregateBalance > 0 ? max(0, intdiv($remainingValue + intdiv($aggregateBalance, 2), $aggregateBalance)) : 0;
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                } elseif ($type === 'sale_return') {
                    $averageCostCents = intdiv(($aggregateQuantity * $currentCostCents) + ($quantity * $capturedCostCents) + intdiv($aggregateBalance, 2), $aggregateBalance);
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                }
                $product->update($productUpdate);
                $branchStock->update(['quantity_on_hand' => self::quantity($branchBalance)]);
                $document->items()->create([
                    'product_id' => $product->id,
                    'quantity' => self::quantity($quantity),
                    'unit_price' => self::money($unitCents),
                    'cost_price' => $capturedCost,
                    'warranty_months' => in_array($type, ['sale', 'resale'], true) ? $product->warranty_months : null,
                    'line_total' => self::money($lineCents),
                ]);
                StockMovement::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'document_id' => $document->id,
                    'type' => $type,
                    'quantity_change' => self::quantity($change),
                    'balance_after' => self::quantity($branchBalance),
                    'created_by' => $userId,
                ]);
            }

            return $document->load('branch', 'contact', 'items.product', 'purchase', 'sale', 'creator:id,name', 'installments', 'payments.receiver');
        });
    }

    public function recordSalePayment(Document $document, array $data, int $userId, int $organizationId): Document
    {
        return DB::transaction(function () use ($document, $data, $userId, $organizationId) {
            $sale = Document::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($document->id);
            if (! in_array($sale->type, ['sale', 'resale'], true) || ! in_array($sale->payment_type, ['due', 'installment'], true)) {
                throw ValidationException::withMessages(['payment' => 'Payments can only be recorded against an unpaid due or installment sale.']);
            }

            $amountCents = self::scaled($data['amount'], 2);
            $balanceCents = self::scaled($sale->total, 2) - self::scaled($sale->amount_paid, 2);
            if ($amountCents <= 0 || $amountCents > $balanceCents) {
                throw ValidationException::withMessages(['amount' => 'Payment amount cannot exceed the remaining sale balance.']);
            }

            $installment = null;
            if ($sale->payment_type === 'installment') {
                if (empty($data['sale_installment_id'])) {
                    throw ValidationException::withMessages(['sale_installment_id' => 'Select the installment being paid.']);
                }
                $installment = SaleInstallment::query()->where('document_id', $sale->id)->lockForUpdate()->find($data['sale_installment_id']);
                if (! $installment) {
                    throw ValidationException::withMessages(['sale_installment_id' => 'Select an installment from this sale.']);
                }
                $installmentBalance = self::scaled($installment->amount, 2) - self::scaled($installment->amount_paid, 2);
                if ($amountCents > $installmentBalance) {
                    throw ValidationException::withMessages(['amount' => 'Payment amount cannot exceed the selected installment balance.']);
                }
                $installment->update(['amount_paid' => self::money(self::scaled($installment->amount_paid, 2) + $amountCents)]);
            }

            $sale->payments()->create([
                'organization_id' => $organizationId,
                'branch_id' => $sale->branch_id,
                'sale_installment_id' => $installment?->id,
                'amount' => self::money($amountCents),
                'payment_method' => $data['payment_method'],
                'kind' => 'payment',
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $sale->update(['amount_paid' => self::money(self::scaled($sale->amount_paid, 2) + $amountCents)]);

            return $sale->fresh()->load('branch', 'contact', 'items.product', 'creator:id,name', 'installments', 'payments.receiver');
        });
    }

    public function adjust(Product $product, string $change, ?string $notes, int $userId, int $organizationId, int $branchId): StockMovement
    {
        return DB::transaction(function () use ($product, $change, $notes, $userId, $organizationId, $branchId) {
            $locked = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($product->id);
            ProductStock::query()->firstOrCreate([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'product_id' => $locked->id,
            ], ['quantity_on_hand' => 0]);
            $branchStock = ProductStock::query()->where('branch_id', $branchId)->where('product_id', $locked->id)->lockForUpdate()->firstOrFail();
            $delta = self::scaled($change, 3);
            $balance = self::scaled($branchStock->quantity_on_hand, 3) + $delta;
            $aggregateBalance = self::scaled($locked->quantity_on_hand, 3) + $delta;
            if ($delta === 0 || $balance < 0) {
                throw ValidationException::withMessages(['quantity_change' => 'Adjustment must be nonzero and cannot make stock negative.']);
            }
            $locked->update(['quantity_on_hand' => self::quantity($aggregateBalance)]);
            $branchStock->update(['quantity_on_hand' => self::quantity($balance)]);

            return StockMovement::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'product_id' => $locked->id,
                'type' => 'adjustment',
                'quantity_change' => self::quantity($delta),
                'balance_after' => self::quantity($balance),
                'notes' => $notes,
                'created_by' => $userId,
            ])->load('product');
        });
    }

    private function paymentTerms(array $data, string $type, string $saleChannel, int $totalCents): array
    {
        if (! in_array($type, ['sale', 'resale'], true)) {
            return [
                'payment_type' => null, 'payment_method' => null, 'initial_payment' => 0, 'down_payment' => 0,
                'installment_count' => null, 'installment_frequency' => null, 'first_installment_date' => null, 'remaining' => 0,
            ];
        }

        $providedType = $data['payment_type'] ?? null;
        $providedAmount = self::scaled($data['amount_paid'] ?? 0, 2);
        $paymentType = $providedType ?: ($saleChannel === 'pos' && $providedAmount >= $totalCents && $totalCents > 0 ? 'full' : 'due');
        $paymentMethod = $data['payment_method'] ?? null;

        if ($paymentType === 'full') {
            if ($providedAmount > 0 && $providedAmount !== $totalCents) {
                throw ValidationException::withMessages(['amount_paid' => 'Full payment must equal the sale total.']);
            }

            return [
                'payment_type' => 'full', 'payment_method' => $paymentMethod ?: 'cash', 'initial_payment' => $totalCents,
                'down_payment' => $totalCents, 'installment_count' => null, 'installment_frequency' => null,
                'first_installment_date' => null, 'remaining' => 0,
            ];
        }

        if ($paymentType === 'due') {
            $legacyPartialPayment = ! $providedType && $providedAmount > 0;
            if (! $legacyPartialPayment && ($providedAmount > 0 || self::scaled($data['down_payment'] ?? 0, 2) > 0)) {
                throw ValidationException::withMessages(['down_payment' => 'A due sale has no initial payment. Choose an installment plan to take a down payment.']);
            }

            return [
                'payment_type' => 'due', 'payment_method' => $legacyPartialPayment ? ($paymentMethod ?: 'cash') : 'credit', 'initial_payment' => $legacyPartialPayment ? $providedAmount : 0,
                'down_payment' => 0, 'installment_count' => null, 'installment_frequency' => null,
                'first_installment_date' => null, 'remaining' => $totalCents - ($legacyPartialPayment ? $providedAmount : 0),
            ];
        }

        $downPayment = self::scaled($data['down_payment'] ?? $data['amount_paid'] ?? 0, 2);
        $count = (int) ($data['installment_count'] ?? 0);
        $frequency = $data['installment_frequency'] ?? null;
        if ($count < 1 || ! in_array($frequency, ['weekly', 'monthly'], true)) {
            throw ValidationException::withMessages(['installment_count' => 'Choose the number and frequency of installments.']);
        }
        if ($downPayment < 0 || $downPayment >= $totalCents) {
            throw ValidationException::withMessages(['down_payment' => 'Down payment must be zero or more and less than the sale total.']);
        }
        if ($providedAmount > 0 && $providedAmount !== $downPayment) {
            throw ValidationException::withMessages(['amount_paid' => 'Amount paid must match the down payment.']);
        }

        return [
            'payment_type' => 'installment', 'payment_method' => $downPayment > 0 ? ($paymentMethod ?: 'cash') : null,
            'initial_payment' => $downPayment, 'down_payment' => $downPayment, 'installment_count' => $count,
            'installment_frequency' => $frequency, 'first_installment_date' => $data['first_installment_date'] ?? null,
            'remaining' => $totalCents - $downPayment,
        ];
    }

    private function createInstallments(Document $document, int $organizationId, int $branchId, array $terms): void
    {
        $firstDate = $terms['first_installment_date']
            ? Carbon::parse($terms['first_installment_date'])->startOfDay()
            : Carbon::parse($document->document_date)->startOfDay()->add($terms['installment_frequency'] === 'weekly' ? '1 week' : '1 month');
        $baseAmount = intdiv($terms['remaining'], $terms['installment_count']);
        $remainder = $terms['remaining'] % $terms['installment_count'];

        for ($sequence = 1; $sequence <= $terms['installment_count']; $sequence++) {
            $dueDate = $terms['installment_frequency'] === 'weekly'
                ? $firstDate->copy()->addWeeks($sequence - 1)
                : $firstDate->copy()->addMonthsNoOverflow($sequence - 1);
            $amount = $baseAmount + ($sequence === $terms['installment_count'] ? $remainder : 0);
            $document->installments()->create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'sequence' => $sequence,
                'due_date' => $dueDate->toDateString(),
                'amount' => self::money($amount),
            ]);
        }
    }

    private static function scaled(string|int|float $value, int $places): int
    {
        $string = (string) $value;
        $negative = str_starts_with($string, '-');
        $string = ltrim($string, '-');
        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '');
        $factor = 10 ** $places;
        $scaled = ((int) $whole * $factor) + (int) str_pad(substr($fraction, 0, $places), $places, '0');

        return $negative ? -$scaled : $scaled;
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function quantity(int $mills): string
    {
        return number_format($mills / 1000, 3, '.', '');
    }
}
