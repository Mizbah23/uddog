<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function create(array $data, int $organizationId, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($data, $organizationId, $userId) {
            $branches = Branch::query()->where('organization_id', $organizationId)
                ->whereIn('id', [$data['from_branch_id'], $data['to_branch_id']])
                ->where('active', true)->lockForUpdate()->get()->keyBy('id');
            if ($branches->count() !== 2 || $data['from_branch_id'] === $data['to_branch_id']) {
                throw ValidationException::withMessages(['to_branch_id' => 'Choose a different active destination branch.']);
            }

            $transfer = StockTransfer::create([
                'organization_id' => $organizationId,
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'number' => 'TMP-'.bin2hex(random_bytes(12)),
                'transfer_date' => $data['transfer_date'],
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $transfer->update(['number' => 'TRF-'.str_pad((string) $transfer->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($data['items'] as $item) {
                $product = Product::query()->where('organization_id', $organizationId)->where('active', true)->find($item['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'Every transferred product must be active and belong to this company.']);
                }
                $quantity = $this->scaled($item['quantity']);

                foreach ([$data['from_branch_id'], $data['to_branch_id']] as $branchId) {
                    ProductStock::query()->firstOrCreate([
                        'organization_id' => $organizationId,
                        'branch_id' => $branchId,
                        'product_id' => $product->id,
                    ], ['quantity_on_hand' => 0]);
                }
                $stocks = ProductStock::query()->where('product_id', $product->id)
                    ->whereIn('branch_id', [$data['from_branch_id'], $data['to_branch_id']])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('branch_id');
                $source = $stocks[$data['from_branch_id']];
                $destination = $stocks[$data['to_branch_id']];
                $sourceBalance = $this->scaled($source->quantity_on_hand) - $quantity;
                if ($sourceBalance < 0) {
                    throw ValidationException::withMessages(['items' => "Insufficient stock for {$product->name} in {$branches[$data['from_branch_id']]->name}."]);
                }
                $destinationBalance = $this->scaled($destination->quantity_on_hand) + $quantity;
                $source->update(['quantity_on_hand' => $this->quantity($sourceBalance)]);
                $destination->update(['quantity_on_hand' => $this->quantity($destinationBalance)]);
                $transfer->items()->create(['product_id' => $product->id, 'quantity' => $this->quantity($quantity)]);

                StockMovement::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $source->branch_id,
                    'product_id' => $product->id,
                    'stock_transfer_id' => $transfer->id,
                    'type' => 'transfer_out',
                    'quantity_change' => $this->quantity(-$quantity),
                    'balance_after' => $this->quantity($sourceBalance),
                    'notes' => "Transferred to {$branches[$data['to_branch_id']]->name} · {$transfer->number}",
                    'created_by' => $userId,
                ]);
                StockMovement::create([
                    'organization_id' => $organizationId,
                    'branch_id' => $destination->branch_id,
                    'product_id' => $product->id,
                    'stock_transfer_id' => $transfer->id,
                    'type' => 'transfer_in',
                    'quantity_change' => $this->quantity($quantity),
                    'balance_after' => $this->quantity($destinationBalance),
                    'notes' => "Received from {$branches[$data['from_branch_id']]->name} · {$transfer->number}",
                    'created_by' => $userId,
                ]);
            }

            return $transfer->load('fromBranch', 'toBranch', 'items.product', 'creator:id,name');
        });
    }

    private function scaled(string|int|float $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }

    private function quantity(int $mills): string
    {
        return number_format($mills / 1000, 3, '.', '');
    }
}
