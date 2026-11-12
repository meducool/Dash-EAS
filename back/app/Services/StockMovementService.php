<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockMovement;

class StockMovementService
{
    /**
     * Movements held back while netted() runs (static: every service holds
     * its own instance of this class, and a document update may go through
     * several of them). Null when nothing is being netted.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $buffer = null;

    /**
     * Runs `$work` — typically "give back the old lines, then take the new
     * ones" when a sale / purchase / service order is edited — and records
     * only the net change per stock lot: one movement from the first
     * quantity to the last, or none when the quantity ends where it started.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function netted(callable $work)
    {
        if (self::$buffer !== null) {
            return $work(); // already netting: the outer call flushes
        }

        self::$buffer = [];
        try {
            $result = $work();
            $pending = self::$buffer;
        } finally {
            self::$buffer = null;
        }

        foreach ($this->net($pending) as $attributes) {
            $this->insert($attributes);
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $movements  in the order they happened
     * @return array<int, array<string, mixed>>
     */
    private function net(array $movements): array
    {
        $groups = [];
        foreach ($movements as $i => $movement) {
            $key = $movement['stock_id'] === null ? "row-{$i}" : "stock-{$movement['stock_id']}";
            $groups[$key][] = $movement;
        }

        $netted = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $last = $group[count($group) - 1];
            $delta = (int) $last['quantity_after'] - (int) $first['quantity_before'];
            if ($delta === 0) {
                continue;
            }

            // Type, reference and reason of the last movement going the same way as the net change.
            $sameWay = array_values(array_filter($group, fn ($m) => ((int) $m['delta'] > 0) === ($delta > 0)));
            $model = $sameWay ? $sameWay[count($sameWay) - 1] : $last;

            $netted[] = array_merge($model, [
                'quantity_before' => (int) $first['quantity_before'],
                'quantity_after' => (int) $last['quantity_after'],
                'delta' => $delta,
            ]);
        }

        return $netted;
    }

    /**
     * @param  Stock  $stock
     * @param  int|null  $userId
     * @return void
     */
    public function recordAutoCreate($stock, $userId)
    {
        $this->insert([
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'type' => StockMovement::TYPE_AUTO_CREATE,
            'quantity_before' => 0,
            'quantity_after' => 0,
            'delta' => 0,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  Stock  $stock
     * @param  int|null  $userId
     * @return void
     */
    public function recordInitial($stock, $userId)
    {
        $after = (int) $stock->quantity;

        $this->insert([
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'type' => StockMovement::TYPE_INITIAL,
            'quantity_before' => 0,
            'quantity_after' => $after,
            'delta' => $after,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  Stock  $stock
     * @param  int  $qtyAfter
     * @param  int|null  $userId
     * @return void
     */
    public function recordImport($stock, $qtyAfter, $userId)
    {
        $this->insert([
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'type' => StockMovement::TYPE_IMPORT,
            'quantity_before' => 0,
            'quantity_after' => $qtyAfter,
            'delta' => $qtyAfter,
            'reference_type' => 'Import',
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  Stock  $stock
     * @param  int  $before
     * @param  int  $after
     * @param  string  $reason
     * @param  int|null  $userId
     * @return void
     */
    public function recordAdjustment($stock, $before, $after, $reason, $userId)
    {
        $this->insert([
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'type' => StockMovement::TYPE_ADJUSTMENT,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'delta' => $after - $before,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  Stock  $stock
     * @param  int|null  $userId
     * @return void
     */
    public function recordDeletion($stock, $userId)
    {
        $before = (int) $stock->quantity;

        $this->insert([
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'type' => StockMovement::TYPE_DELETION,
            'quantity_before' => $before,
            'quantity_after' => 0,
            'delta' => -$before,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  int  $stockId
     * @param  int|null  $productId
     * @param  int  $before
     * @param  int  $after
     * @param  int  $saleId
     * @param  int|null  $userId
     * @return void
     */
    public function recordSaleOut($stockId, $productId, $before, $after, $saleId, $userId, ?string $reason = null)
    {
        $this->insert([
            'stock_id' => $stockId,
            'product_id' => $productId,
            'type' => StockMovement::TYPE_SALE_OUT,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'delta' => $after - $before,
            'reference_type' => 'Vente',
            'reference_id' => $saleId,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  int  $stockId
     * @param  int|null  $productId
     * @param  int  $before
     * @param  int  $after
     * @param  int  $saleId
     * @param  int|null  $userId
     * @return void
     */
    public function recordSaleIn($stockId, $productId, $before, $after, $saleId, $userId, ?string $reason = null)
    {
        $this->insert([
            'stock_id' => $stockId,
            'product_id' => $productId,
            'type' => StockMovement::TYPE_SALE_IN,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'delta' => $after - $before,
            'reference_type' => 'Vente',
            'reference_id' => $saleId,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  int  $stockId
     * @param  int|null  $productId
     * @param  int  $before
     * @param  int  $after
     * @param  int  $purchaseId
     * @param  int|null  $userId
     * @return void
     */
    public function recordPurchaseIn($stockId, $productId, $before, $after, $purchaseId, $userId, ?string $reason = null)
    {
        $this->insert([
            'stock_id' => $stockId,
            'product_id' => $productId,
            'type' => StockMovement::TYPE_PURCHASE_IN,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'delta' => $after - $before,
            'reference_type' => 'Achat',
            'reference_id' => $purchaseId,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  int  $stockId
     * @param  int|null  $productId
     * @param  int  $before
     * @param  int  $after
     * @param  int  $purchaseId
     * @param  int|null  $userId
     * @return void
     */
    public function recordPurchaseOut($stockId, $productId, $before, $after, $purchaseId, $userId, ?string $reason = null)
    {
        $this->insert([
            'stock_id' => $stockId,
            'product_id' => $productId,
            'type' => StockMovement::TYPE_PURCHASE_OUT,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'delta' => $after - $before,
            'reference_type' => 'Achat',
            'reference_id' => $purchaseId,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    public function recordServiceOut($stockId, $productId, $before, $after, $serviceOrderId, $userId, ?string $reason = null)
    {
        $this->insert([
            'stock_id' => $stockId,
            'product_id' => $productId,
            'type' => StockMovement::TYPE_SERVICE_OUT,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'delta' => $after - $before,
            'reference_type' => 'Service Auto',
            'reference_id' => $serviceOrderId,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    public function recordServiceIn($stockId, $productId, $before, $after, $serviceOrderId, $userId, ?string $reason = null)
    {
        $this->insert([
            'stock_id' => $stockId,
            'product_id' => $productId,
            'type' => StockMovement::TYPE_SERVICE_IN,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'delta' => $after - $before,
            'reference_type' => 'Service Auto',
            'reference_id' => $serviceOrderId,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return void
     */
    /**
     * Only an operation that changes the quantity is a movement: a zero delta
     * (product created with an empty lot, price or depot edited…) is not kept.
     */
    private function insert($attributes)
    {
        if (self::$buffer !== null) {
            self::$buffer[] = $attributes + ['stock_id' => null];

            return;
        }

        if ((int) ($attributes['delta'] ?? 0) === 0) {
            return;
        }

        StockMovement::create($attributes);
    }
}
