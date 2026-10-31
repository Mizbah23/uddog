<?php

namespace App\Services;

use App\Models\Document;
use App\Models\GoldRate;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductDamage;
use App\Models\ProductStock;
use App\Models\PurchaseInstallment;
use App\Models\PurchasePayment;
use App\Models\SaleInstallment;
use App\Models\StockMovement;
use App\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function createDocument(array $data, int $userId, int $organizationId, int $branchId): Document
    {
        return DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($data, $userId, $organizationId, $branchId) {
            $type = $data['type'];
            if (in_array($type, ['sale', 'resale'], true)) {
                app(PackageLimitService::class)->consumeSaleInvoice($organizationId);
            }
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
            $organization = Organization::query()->findOrFail($organizationId);
            $storeSetup = $organization->setup()['store'];
            foreach ($data['items'] as $item) {
                $product = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->find($item['product_id']);
                if (! $product || (! $product->active && ! in_array($type, ['purchase_return', 'sale_return'], true))) {
                    throw ValidationException::withMessages(['items' => 'Every product must be active.']);
                }
                $enteredUnit = $item['unit'] ?? $product->unit;
                $pieceProduct = in_array($product->unit, ['pc', 'pcs'], true) && ! $product->is_gold_item;
                $nonPieceUnits = ['kg', 'g', 'L', 'oz', 'ounce', 'ভরি', 'রতি'];
                if (in_array($type, ['purchase_return', 'sale_return'], true) && $enteredUnit !== $product->unit) {
                    throw ValidationException::withMessages(['items' => 'Returns must use the product base unit.']);
                }
                if ($enteredUnit !== $product->unit && (! $pieceProduct || ! array_key_exists($enteredUnit, $storeSetup['unit_conversions']) || in_array($enteredUnit, $nonPieceUnits, true))) {
                    throw ValidationException::withMessages(['items' => "Unit {$enteredUnit} cannot be used for {$product->name}."]);
                }
                $conversion = $enteredUnit === $product->unit ? 1 : (int) ($storeSetup['unit_conversions'][$enteredUnit] ?? 1);
                $enteredQuantity = self::scaled($item['quantity'], 3);
                $quantity = $enteredQuantity * $conversion;
                if ($product->usesWholePieces() && $quantity % 1000 !== 0) {
                    throw ValidationException::withMessages(['items' => "{$product->name} is counted in whole pieces. Enter a whole-piece quantity."]);
                }
                if ($conversion < 1 || $quantity > 99999999999999) {
                    throw ValidationException::withMessages(['items' => 'Converted quantity exceeds the supported stock range.']);
                }
                $enteredPriceCents = self::scaled($item['unit_price'], 2);
                $unitCents = intdiv($enteredPriceCents + intdiv($conversion, 2), $conversion);
                $sourceCostCents = null;
                if ($product->stock_source_product_id && in_array($type, ['purchase', 'purchase_return'], true)) {
                    throw ValidationException::withMessages(['items' => "{$product->name} is a sales product. Purchase its linked stock product instead."]);
                }
                if ($product->stock_source_product_id && in_array($type, ['sale', 'resale'], true) && ! $organization->shared_stock_sales) {
                    throw ValidationException::withMessages(['items' => 'Shared stock sales are not enabled for this company.']);
                }
                $stockProductId = $product->stock_source_product_id ?: $product->id;
                $stockQuantity = $quantity;
                if ($product->stock_source_product_id) {
                    $sourceRatio = self::scaled($product->stock_source_quantity, 3);
                    if ($quantity > intdiv(PHP_INT_MAX - 500, $sourceRatio)) {
                        throw ValidationException::withMessages(['items' => 'The linked stock quantity is outside the supported range.']);
                    }
                    $stockQuantity = intdiv($quantity * $sourceRatio + 500, 1000);
                    if ($stockQuantity < 1 || $stockQuantity > 99999999999999) {
                        throw ValidationException::withMessages(['items' => 'The linked stock quantity is outside the supported range.']);
                    }
                }
                $goldSnapshot = $this->goldSnapshot($product, $organization->gold_store && in_array($type, ['sale', 'resale'], true) ? $branchId : null, $data['document_date']);
                if ($sale) {
                    $soldItem = $sale->items()->where('product_id', $product->id)->first();
                    if (! $soldItem) {
                        throw ValidationException::withMessages(['items' => "{$product->name} was not included in the selected sale."]);
                    }
                    $unitCents = self::scaled($soldItem->unit_price, 2);
                    $sourceCostCents = self::scaled($soldItem->cost_price, 2);
                    $stockProductId = $soldItem->stock_product_id ?: $soldItem->product_id;
                    $soldQuantity = self::scaled($soldItem->quantity, 3);
                    $soldStockQuantity = self::scaled($soldItem->stock_quantity ?? $soldItem->quantity, 3);
                    $priorReturns = DB::connection(app(TenantContext::class)->connectionName())->table('document_items')
                        ->join('documents', 'documents.id', '=', 'document_items.document_id')
                        ->where('documents.sale_id', $sale->id)
                        ->where('document_items.product_id', $product->id);
                    $returnedQuantity = self::scaled((clone $priorReturns)->sum('document_items.quantity'), 3);
                    $returnedStockQuantity = self::scaled((clone $priorReturns)->sum(DB::raw('COALESCE(document_items.stock_quantity, document_items.quantity)')), 3);
                    $cumulativeReturn = min($soldQuantity, $returnedQuantity + $quantity);
                    $targetStockReturned = intdiv($cumulativeReturn * $soldStockQuantity + intdiv($soldQuantity, 2), $soldQuantity);
                    $stockQuantity = max(0, $targetStockReturned - $returnedStockQuantity);
                    $goldSnapshot = collect($soldItem->only(['gold_gross_weight', 'gold_net_weight', 'gold_purity', 'gold_rate', 'making_charge', 'wastage_weight', 'stone_value', 'tag_number', 'hallmark_code']))->all();
                } elseif ($organization->gold_store && in_array($type, ['sale', 'resale'], true) && $product->is_gold_item) {
                    if (! $goldSnapshot['gold_rate']) {
                        throw ValidationException::withMessages(['items' => "No current {$product->gold_purity} rate is recorded for {$product->name}."]);
                    }
                    $unitCents = self::scaled($goldSnapshot['unit_price'], 2);
                }
                if ($sale || ($organization->gold_store && in_array($type, ['sale', 'resale'], true) && $product->is_gold_item)) {
                    $enteredPriceCents = $unitCents;
                }
                $lineCents = intdiv($enteredQuantity * $enteredPriceCents + 500, 1000);
                $subtotalCents += $lineCents;
                $items[] = [$product, $quantity, $unitCents, $lineCents, $sourceCostCents, $goldSnapshot, $enteredUnit, $conversion, $enteredQuantity, $enteredPriceCents, $stockProductId, $stockQuantity];
            }

            $discountCents = $type === 'sale_return' ? 0 : self::scaled($data['discount'] ?? 0, 2);
            $taxCents = $type === 'sale_return' ? 0 : self::scaled($data['tax'] ?? 0, 2);
            if ($discountCents > $subtotalCents) {
                throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the subtotal.']);
            }
            $totalCents = $subtotalCents - $discountCents + $taxCents;
            $saleChannel = $data['sale_channel'] ?? 'standard';
            $exchange = $this->goldExchange($data, $type, $organization->gold_store, $organizationId, $branchId);
            if ($exchange['value'] > $totalCents) {
                throw ValidationException::withMessages(['gold_exchange_weight' => 'Old-gold exchange value cannot exceed the sale total.']);
            }
            $paymentTerms = $this->paymentTerms([...$data, 'gold_exchange_value' => self::money($exchange['value'])], $type, $saleChannel, $totalCents);
            $totalCents += $paymentTerms['interest_amount'] ?? 0;

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
                'interest_rate' => $paymentTerms['interest_rate'] ?? 0,
                'interest_amount' => self::money($paymentTerms['interest_amount'] ?? 0),
                'gold_exchange_weight' => $exchange['weight'],
                'gold_exchange_purity' => $exchange['purity'],
                'gold_exchange_rate' => self::money($exchange['rate']),
                'gold_exchange_value' => self::money($exchange['value']),
                'payment_method' => in_array($type, ['sale_return', 'purchase_return'], true)
                    ? ($data['payment_method'] ?? null) : $paymentTerms['payment_method'],
                'payment_type' => $paymentTerms['payment_type'],
                'amount_paid' => self::money($paymentTerms['initial_payment']),
                'down_payment' => self::money($paymentTerms['down_payment']),
                'installment_count' => $paymentTerms['installment_count'],
                'installment_frequency' => $paymentTerms['installment_frequency'],
                'installment_start_date' => $paymentTerms['installment_start_date'] ?? null,
                'first_installment_date' => $paymentTerms['first_installment_date'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $prefix = ['purchase' => 'PUR', 'sale' => 'SAL', 'resale' => 'RSL', 'purchase_return' => 'PRT', 'sale_return' => 'SRT'][$type];
            $document->update(['number' => $prefix.'-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT)]);

            if (($paymentTerms['exchange_value'] ?? 0) > 0 && $type !== 'purchase') {
                $document->payments()->create([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'amount' => self::money($paymentTerms['exchange_value']),
                    'payment_method' => 'gold_exchange',
                    'payment_date' => $data['document_date'],
                    'kind' => 'gold_exchange',
                    'notes' => 'Old gold exchange credit',
                    'created_by' => $userId,
                ]);
            }
            $cashInitialPayment = $paymentTerms['initial_payment'] - ($paymentTerms['exchange_value'] ?? 0);
            if ($cashInitialPayment > 0) {
                $initialPayment = [
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'amount' => self::money($cashInitialPayment),
                    'payment_method' => $paymentTerms['payment_method'],
                    'payment_date' => $data['document_date'],
                    'created_by' => $userId,
                ];
                if ($type === 'purchase') {
                    PurchasePayment::create([...$initialPayment, 'document_id' => $document->id]);
                } else {
                    $document->payments()->create([...$initialPayment, 'kind' => $paymentTerms['payment_type'] === 'installment' ? 'down_payment' : 'initial']);
                }
            }
            if ($paymentTerms['payment_type'] === 'installment') {
                if ($type === 'purchase') {
                    $this->createPurchaseInstallments($document, $organizationId, $branchId, $paymentTerms);
                } else {
                    $this->createInstallments($document, $organizationId, $branchId, $paymentTerms);
                }
            }

            foreach ($items as [$product, $quantity, $unitCents, $lineCents, $sourceCostCents, $goldSnapshot, $enteredUnit, $conversion, $enteredQuantity, $enteredPriceCents, $stockProductId, $stockQuantity]) {
                if ($purchase) {
                    $purchased = self::scaled($purchase->items()->where('product_id', $product->id)->sum('quantity'), 3);
                    $returned = self::scaled(DB::connection(app(TenantContext::class)->connectionName())->table('document_items')
                        ->join('documents', 'documents.id', '=', 'document_items.document_id')
                        ->where('documents.purchase_id', $purchase->id)
                        ->where('document_items.product_id', $product->id)
                        ->sum('document_items.quantity'), 3);
                    $damaged = self::scaled(ProductDamage::query()->where('purchase_id', $purchase->id)->where('product_id', $product->id)->sum('quantity'), 3);
                    if ($quantity > $purchased - $returned - $damaged) {
                        throw ValidationException::withMessages(['items' => "Return quantity exceeds the remaining purchased and undamaged quantity for {$product->name}."]);
                    }
                }
                if ($sale) {
                    $sold = self::scaled($sale->items()->where('product_id', $product->id)->sum('quantity'), 3);
                    $returned = self::scaled(DB::connection(app(TenantContext::class)->connectionName())->table('document_items')
                        ->join('documents', 'documents.id', '=', 'document_items.document_id')
                        ->where('documents.sale_id', $sale->id)
                        ->where('document_items.product_id', $product->id)
                        ->sum('document_items.quantity'), 3);
                    if ($quantity > $sold - $returned) {
                        throw ValidationException::withMessages(['items' => "Return quantity exceeds the remaining sold quantity for {$product->name}."]);
                    }
                }

                $stockProduct = $stockProductId === $product->id ? $product : Product::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($stockProductId);
                if ($stockProduct->usesWholePieces() && $stockQuantity % 1000 !== 0) {
                    throw ValidationException::withMessages(['items' => "{$stockProduct->name} is counted in whole pieces. The selected quantity would use a fraction of a piece."]);
                }
                ProductStock::query()->firstOrCreate([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'product_id' => $stockProductId,
                ], ['quantity_on_hand' => 0]);
                $branchStock = ProductStock::query()->where('branch_id', $branchId)->where('product_id', $stockProductId)->lockForUpdate()->firstOrFail();
                $branchQuantity = self::scaled($branchStock->quantity_on_hand, 3);
                $aggregateQuantity = self::scaled($stockProduct->quantity_on_hand, 3);
                $currentCostCents = self::scaled($stockProduct->cost_price, 2);
                $capturedCostCents = $sourceCostCents ?? intdiv($currentCostCents * $stockQuantity + intdiv($quantity, 2), $quantity);
                $capturedStockCostCents = $sourceCostCents === null ? $currentCostCents : ($stockQuantity > 0 ? intdiv($sourceCostCents * $quantity + intdiv($stockQuantity, 2), $stockQuantity) : 0);
                $capturedCost = self::money($capturedCostCents);
                $change = in_array($type, ['purchase', 'sale_return'], true) ? $stockQuantity : -$stockQuantity;
                $branchBalance = $branchQuantity + $change;
                $aggregateBalance = $aggregateQuantity + $change;
                if ($branchBalance < 0) {
                    throw ValidationException::withMessages(['items' => "Insufficient stock for {$product->name} ({$stockProduct->name})."]);
                }
                $productUpdate = ['quantity_on_hand' => self::quantity($aggregateBalance)];
                if ($type === 'purchase') {
                    $averageCostCents = intdiv(($aggregateQuantity * $currentCostCents) + ($lineCents * 1000) + intdiv($aggregateBalance, 2), $aggregateBalance);
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                } elseif ($type === 'purchase_return') {
                    $remainingValue = ($aggregateQuantity * $currentCostCents) - ($lineCents * 1000);
                    $averageCostCents = $aggregateBalance > 0 ? max(0, intdiv($remainingValue + intdiv($aggregateBalance, 2), $aggregateBalance)) : 0;
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                } elseif ($type === 'sale_return') {
                    $averageCostCents = $aggregateBalance > 0 ? intdiv(($aggregateQuantity * $currentCostCents) + ($stockQuantity * $capturedStockCostCents) + intdiv($aggregateBalance, 2), $aggregateBalance) : $currentCostCents;
                    $productUpdate['cost_price'] = self::money($averageCostCents);
                }
                $stockProduct->update($productUpdate);
                $branchStock->update(['quantity_on_hand' => self::quantity($branchBalance)]);
                $document->items()->create([
                    'product_id' => $product->id,
                    'stock_product_id' => $stockProductId,
                    'stock_quantity' => self::quantity($stockQuantity),
                    'quantity' => self::quantity($quantity),
                    'unit_price' => self::money($unitCents),
                    'entered_unit' => $enteredUnit,
                    'unit_conversion' => $conversion,
                    'entered_quantity' => self::quantity($enteredQuantity),
                    'entered_unit_price' => self::money($enteredPriceCents),
                    'cost_price' => $capturedCost,
                    'warranty_months' => in_array($type, ['sale', 'resale'], true) ? $product->warranty_months : null,
                    'line_total' => self::money($lineCents),
                    'gold_gross_weight' => $goldSnapshot['gold_gross_weight'],
                    'gold_net_weight' => $goldSnapshot['gold_net_weight'],
                    'gold_purity' => $goldSnapshot['gold_purity'],
                    'gold_rate' => $goldSnapshot['gold_rate'],
                    'making_charge' => $goldSnapshot['making_charge'],
                    'wastage_weight' => $goldSnapshot['wastage_weight'],
                    'stone_value' => $goldSnapshot['stone_value'],
                    'tag_number' => $goldSnapshot['tag_number'],
                    'hallmark_code' => $goldSnapshot['hallmark_code'],
                ]);
                StockMovement::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'product_id' => $stockProductId,
                    'document_id' => $document->id,
                    'type' => $type,
                    'quantity_change' => self::quantity($change),
                    'balance_after' => self::quantity($branchBalance),
                    'notes' => $stockProductId !== $product->id ? ($type === 'sale_return' ? 'Returned as ' : 'Sold as ').$product->name : null,
                    'created_by' => $userId,
                ]);
            }

            return $document->load('branch', 'contact', 'items.product', 'purchase', 'sale', 'creator:id,name', 'installments', 'payments.receiver', 'purchaseInstallments');
        });
    }

    public function recordSalePayment(Document $document, array $data, int $userId, int $organizationId): Document
    {
        return DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($document, $data, $userId, $organizationId) {
            $sale = Document::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($document->id);
            $balanceCents = self::scaled($sale->total, 2) - self::scaled($sale->amount_paid, 2);
            if (! in_array($sale->type, ['sale', 'resale'], true) || $balanceCents <= 0) {
                throw ValidationException::withMessages(['payment' => 'Payments can only be recorded against an unpaid due or installment sale.']);
            }

            $amountCents = self::scaled($data['amount'], 2);
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
            $sale->update([
                'amount_paid' => self::money(self::scaled($sale->amount_paid, 2) + $amountCents),
                'payment_type' => in_array($sale->payment_type, ['due', 'installment'], true) ? $sale->payment_type : 'due',
            ]);

            return $sale->fresh()->load('branch', 'contact', 'items.product', 'creator:id,name', 'installments', 'payments.receiver');
        });
    }

    public function adjust(Product $product, string $change, ?string $notes, int $userId, int $organizationId, int $branchId): StockMovement
    {
        return DB::connection(app(TenantContext::class)->connectionName())->transaction(function () use ($product, $change, $notes, $userId, $organizationId, $branchId) {
            $locked = Product::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($product->id);
            if ($locked->stock_source_product_id) {
                throw ValidationException::withMessages(['product_id' => 'Adjust the linked stock product instead of this sales product.']);
            }
            ProductStock::query()->firstOrCreate([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'product_id' => $locked->id,
            ], ['quantity_on_hand' => 0]);
            $branchStock = ProductStock::query()->where('branch_id', $branchId)->where('product_id', $locked->id)->lockForUpdate()->firstOrFail();
            $delta = self::scaled($change, 3);
            if ($locked->usesWholePieces() && $delta % 1000 !== 0) {
                throw ValidationException::withMessages(['quantity_change' => 'Enter a whole-piece adjustment for this product.']);
            }
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
        if ($type === 'purchase') {
            $paymentType = $data['payment_type'] ?? 'due';
            $paymentMethod = $data['payment_method'] ?? 'cash';
            if ($paymentType === 'full') {
                $providedAmount = self::scaled($data['amount_paid'] ?? 0, 2);
                if ($providedAmount > 0 && $providedAmount !== $totalCents) {
                    throw ValidationException::withMessages(['amount_paid' => 'Full payment must equal the purchase total.']);
                }

                return [
                    'payment_type' => 'full', 'payment_method' => $paymentMethod, 'initial_payment' => $totalCents,
                    'down_payment' => $totalCents, 'installment_count' => null, 'installment_frequency' => null,
                    'first_installment_date' => null, 'remaining' => 0,
                ];
            }

            if ($paymentType === 'due') {
                return [
                    'payment_type' => 'due', 'payment_method' => 'credit', 'initial_payment' => 0,
                    'down_payment' => 0, 'installment_count' => null, 'installment_frequency' => null,
                    'first_installment_date' => null, 'remaining' => $totalCents,
                ];
            }

            $downPayment = self::scaled($data['down_payment'] ?? 0, 2);
            $count = (int) ($data['installment_count'] ?? 0);
            $frequency = $data['installment_frequency'] ?? null;
            if ($count < 1 || ! in_array($frequency, ['weekly', 'monthly'], true)) {
                throw ValidationException::withMessages(['installment_count' => 'Choose the number and frequency of installments.']);
            }
            if ($downPayment < 0 || $downPayment >= $totalCents) {
                throw ValidationException::withMessages(['down_payment' => 'Down payment must be zero or more and less than the purchase total.']);
            }

            return [
                'payment_type' => 'installment', 'payment_method' => $downPayment > 0 ? $paymentMethod : null,
                'initial_payment' => $downPayment, 'down_payment' => $downPayment, 'installment_count' => $count,
                'installment_frequency' => $frequency, 'first_installment_date' => $data['first_installment_date'] ?? null,
                'remaining' => $totalCents - $downPayment,
            ];
        }

        if (! in_array($type, ['sale', 'resale'], true)) {
            return [
                'payment_type' => null, 'payment_method' => null, 'initial_payment' => 0, 'down_payment' => 0,
                'installment_count' => null, 'installment_frequency' => null, 'first_installment_date' => null, 'remaining' => 0,
            ];
        }

        $providedType = $data['payment_type'] ?? null;
        $providedAmount = self::scaled($data['amount_paid'] ?? 0, 2);
        $exchangeCents = self::scaled($data['gold_exchange_value'] ?? 0, 2);
        $paymentType = $providedType ?: ($saleChannel === 'pos' && $providedAmount >= $totalCents && $totalCents > 0 ? 'full' : 'due');
        $paymentMethod = $data['payment_method'] ?? null;

        if ($paymentType === 'full') {
            if ($providedAmount > 0 && $providedAmount !== $totalCents - $exchangeCents) {
                throw ValidationException::withMessages(['amount_paid' => 'Full payment must equal the sale total.']);
            }

            return [
                'payment_type' => 'full', 'payment_method' => $paymentMethod ?: 'cash', 'initial_payment' => $totalCents,
                'down_payment' => $totalCents, 'installment_count' => null, 'installment_frequency' => null,
                'first_installment_date' => null, 'remaining' => 0, 'exchange_value' => $exchangeCents,
            ];
        }

        if ($paymentType === 'due') {
            $legacyPartialPayment = ! $providedType && $providedAmount > 0;
            if (! $legacyPartialPayment && ($providedAmount > 0 || self::scaled($data['down_payment'] ?? 0, 2) > 0)) {
                throw ValidationException::withMessages(['down_payment' => 'A due sale has no initial payment. Choose an installment plan to take a down payment.']);
            }
            $initialPayment = $exchangeCents + ($legacyPartialPayment ? $providedAmount : 0);

            return [
                'payment_type' => 'due', 'payment_method' => $legacyPartialPayment ? ($paymentMethod ?: 'cash') : 'credit', 'initial_payment' => $initialPayment,
                'down_payment' => 0, 'installment_count' => null, 'installment_frequency' => null,
                'first_installment_date' => null, 'remaining' => $totalCents - $initialPayment, 'exchange_value' => $exchangeCents,
            ];
        }

        $downPayment = self::scaled($data['down_payment'] ?? $data['amount_paid'] ?? 0, 2);
        $count = (int) ($data['installment_count'] ?? 0);
        $frequency = $data['installment_frequency'] ?? null;
        if ($count < 1 || ! in_array($frequency, ['weekly', 'half_monthly', 'monthly'], true)) {
            throw ValidationException::withMessages(['installment_count' => 'Choose the number and frequency of installments.']);
        }
        if ($exchangeCents >= $totalCents || $downPayment < 0 || $downPayment >= $totalCents - $exchangeCents) {
            throw ValidationException::withMessages(['down_payment' => 'Down payment must be zero or more and less than the sale total.']);
        }
        if ($providedAmount > 0 && $providedAmount !== $downPayment) {
            throw ValidationException::withMessages(['amount_paid' => 'Amount paid must match the down payment.']);
        }

        $interestRate = (float) ($data['interest_rate'] ?? 0);
        $interestRateScaled = self::scaled($interestRate, 2);
        $principalCents = $totalCents - $exchangeCents - $downPayment;
        $interestCents = intdiv(($principalCents * $interestRateScaled) + 5000, 10000);

        $startDate = $saleChannel === 'pos'
            ? Carbon::parse($data['installment_start_date'] ?? $data['document_date'])->startOfDay()
            : null;

        return [
            'payment_type' => 'installment', 'payment_method' => $downPayment > 0 ? ($paymentMethod ?: 'cash') : null,
            'initial_payment' => $exchangeCents + $downPayment, 'down_payment' => $downPayment, 'installment_count' => $count,
            'installment_frequency' => $frequency, 'installment_start_date' => $startDate?->toDateString(),
            'first_installment_date' => $startDate
                ? $this->installmentDueDate($startDate, $frequency, 1)->toDateString()
                : ($data['first_installment_date'] ?? null),
            'remaining' => $principalCents + $interestCents,
            'interest_rate' => number_format($interestRate, 2, '.', ''), 'interest_amount' => $interestCents, 'exchange_value' => $exchangeCents,
        ];
    }

    /** @return array<string, mixed> */
    private function goldSnapshot(Product $product, ?int $branchId, string $documentDate): array
    {
        $snapshot = [
            'gold_gross_weight' => $product->is_gold_item ? $product->gold_gross_weight : null,
            'gold_net_weight' => null,
            'gold_purity' => $product->is_gold_item ? $product->gold_purity : null,
            'gold_rate' => null,
            'making_charge' => 0,
            'wastage_weight' => 0,
            'stone_value' => $product->is_gold_item ? $product->stone_value : 0,
            'tag_number' => $product->is_gold_item ? $product->tag_number : null,
            'hallmark_code' => $product->is_gold_item ? $product->hallmark_code : null,
            'unit_price' => null,
        ];
        if (! $product->is_gold_item) {
            return $snapshot;
        }

        $netWeight = max(0, (float) $product->gold_gross_weight - (float) $product->gold_stone_weight - (float) $product->gold_other_weight);
        $snapshot['gold_net_weight'] = number_format($netWeight, 3, '.', '');
        if (! $branchId) {
            return $snapshot;
        }
        $rate = GoldRate::query()->where('organization_id', $product->organization_id)->where('branch_id', $branchId)
            ->where('metal', 'gold')->whereRaw('UPPER(purity) = ?', [strtoupper((string) $product->gold_purity)])
            ->whereDate('effective_on', '<=', $documentDate)->orderByDesc('effective_on')->first();
        if (! $rate) {
            return $snapshot;
        }

        $wastageWeight = $netWeight * (float) $product->wastage_percent / 100;
        $metalValue = ($netWeight + $wastageWeight) * (float) $rate->rate_per_gram;
        $makingCharge = match ($product->making_charge_mode) {
            'percentage' => $metalValue * (float) $product->making_charge / 100,
            'flat' => (float) $product->making_charge,
            default => $netWeight * (float) $product->making_charge,
        };
        $snapshot['gold_rate'] = $rate->rate_per_gram;
        $snapshot['making_charge'] = number_format($makingCharge, 2, '.', '');
        $snapshot['wastage_weight'] = number_format($wastageWeight, 3, '.', '');
        $snapshot['unit_price'] = number_format($metalValue + $makingCharge + (float) $product->stone_value, 2, '.', '');

        return $snapshot;
    }

    /** @return array{weight: string, purity: ?string, rate: int, value: int} */
    private function goldExchange(array $data, string $type, bool $goldStore, int $organizationId, int $branchId): array
    {
        $weight = self::scaled($data['gold_exchange_weight'] ?? 0, 3);
        if (! $goldStore || ! in_array($type, ['sale', 'resale'], true) || $weight <= 0) {
            return ['weight' => '0.000', 'purity' => null, 'rate' => 0, 'value' => 0];
        }
        $purity = strtoupper((string) ($data['gold_exchange_purity'] ?? ''));
        $rate = GoldRate::query()->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)->where('metal', 'gold')->whereRaw('UPPER(purity) = ?', [$purity])
            ->whereDate('effective_on', '<=', $data['document_date'])->orderByDesc('effective_on')->first();
        if (! $rate) {
            throw ValidationException::withMessages(['gold_exchange_purity' => 'No current rate is recorded for the old gold purity.']);
        }
        $value = intdiv(($weight * self::scaled($rate->rate_per_gram, 2)) + 500, 1000);

        return ['weight' => self::quantity($weight), 'purity' => $purity, 'rate' => self::scaled($rate->rate_per_gram, 2), 'value' => $value];
    }

    private function createInstallments(Document $document, int $organizationId, int $branchId, array $terms): void
    {
        $startDate = $terms['installment_start_date'] ?? null;
        $firstDate = $terms['first_installment_date']
            ? Carbon::parse($terms['first_installment_date'])->startOfDay()
            : $this->installmentDueDate(Carbon::parse($document->document_date)->startOfDay(), $terms['installment_frequency'], 1);
        $baseAmount = intdiv($terms['remaining'], $terms['installment_count']);
        $remainder = $terms['remaining'] % $terms['installment_count'];

        for ($sequence = 1; $sequence <= $terms['installment_count']; $sequence++) {
            $dueDate = $startDate
                ? $this->installmentDueDate(Carbon::parse($startDate)->startOfDay(), $terms['installment_frequency'], $sequence)
                : $this->installmentDueDate($firstDate, $terms['installment_frequency'], $sequence - 1);
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

    private function installmentDueDate(Carbon $anchor, string $frequency, int $intervals): Carbon
    {
        return match ($frequency) {
            'weekly' => $anchor->copy()->addWeeks($intervals),
            'half_monthly' => $anchor->copy()->addDays(15 * $intervals),
            'monthly' => $anchor->copy()->addMonthsNoOverflow($intervals),
        };
    }

    private function createPurchaseInstallments(Document $document, int $organizationId, int $branchId, array $terms): void
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
            PurchaseInstallment::create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'document_id' => $document->id,
                'sequence' => $sequence,
                'due_date' => $dueDate->toDateString(),
                'amount' => self::money($amount),
                'amount_paid' => '0.00',
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
