<?php

namespace App\Domain\Purchases;

use App\Enums\PurchaseStatus;
use Illuminate\Support\Facades\DB;

/**
 * Purchase price of a product, read from its purchases (cancelled ones left
 * out) — fills "PU Achat" in the sale form.
 *
 * The lot's own purchases are used when the lot was bought at least once,
 * else every purchase of the product. When several prices are found the
 * highest is proposed (prudent margin) and every price is returned so the
 * user can check by hand.
 */
class PurchasePriceService
{
    /**
     * @return array{price: float|null, multiple: bool, scope: string|null, prices: array<int, array<string, mixed>>}
     */
    public function forProduct(int $productId, ?int $stockId = null): array
    {
        $rows = $stockId ? $this->rows($productId, $stockId) : collect();
        $scope = 'stock';
        if ($rows->isEmpty()) {
            $rows = $this->rows($productId, null);
            $scope = 'product';
        }

        if ($rows->isEmpty()) {
            return ['price' => null, 'multiple' => false, 'scope' => null, 'prices' => []];
        }

        $prices = $rows
            ->groupBy(fn ($row) => number_format((float) $row->unit_price, 2, '.', ''))
            ->map(fn ($group, $price) => [
                'unit_price' => (float) $price,
                'purchases_count' => $group->pluck('purchase_id')->unique()->count(),
                'quantity' => (int) $group->sum('quantity'),
                'last_date' => $group->max('date'),
                'last_purchase_id' => (int) $group->sortByDesc('date')->first()->purchase_id,
                'suppliers' => $group->pluck('supplier')->filter()->unique()->values()->all(),
            ])
            ->sortByDesc('unit_price')
            ->values();

        return [
            'price' => $prices->first()['unit_price'],
            'multiple' => $prices->count() > 1,
            'scope' => $scope,
            'prices' => $prices->all(),
        ];
    }

    /**
     * Purchase history of a product (most recent first): every purchase line
     * with its supplier, lot and unit price — product sheet « Historique des achats ».
     * Cancelled purchases are listed (flagged) but left out of the summary.
     *
     * @return array{data: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function history(int $productId, int $limit = 200): array
    {
        $lines = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->leftJoin('stocks', 'stocks.id', '=', 'purchase_items.stock_id')
            ->where('purchase_items.product_id', $productId)
            ->orderByDesc('purchases.date')
            ->orderByDesc('purchases.id')
            ->limit($limit)
            ->get([
                'purchase_items.id',
                'purchase_items.purchase_id',
                'purchase_items.quantity',
                'purchase_items.unit_price',
                'purchases.date',
                'purchases.status',
                'purchases.bl_number',
                'purchases.invoice_number',
                'suppliers.id as supplier_id',
                'suppliers.name as supplier',
                'stocks.depot',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'purchase_id' => (int) $row->purchase_id,
                'date' => $row->date,
                'status' => $row->status,
                'cancelled' => $row->status === PurchaseStatus::ANNULE->value,
                'bl_number' => $row->bl_number,
                'invoice_number' => $row->invoice_number,
                'supplier' => $row->supplier_id ? ['id' => (int) $row->supplier_id, 'name' => $row->supplier] : null,
                'depot' => $row->depot,
                'quantity' => (int) $row->quantity,
                'unit_price' => round((float) $row->unit_price, 2),
            ]);

        $valid = $lines->where('cancelled', false)->where('unit_price', '>', 0);
        $quantity = (int) $valid->sum('quantity');

        return [
            'data' => $lines->values()->all(),
            'summary' => [
                'purchases_count' => $valid->pluck('purchase_id')->unique()->count(),
                'quantity' => $quantity,
                'last_price' => $valid->first()['unit_price'] ?? null,
                'min_price' => $valid->min('unit_price'),
                'max_price' => $valid->max('unit_price'),
                // Weighted by quantity: what one tyre cost on average.
                'average_price' => $quantity > 0 ? round($valid->sum(fn ($l) => $l['unit_price'] * $l['quantity']) / $quantity, 2) : null,
                'suppliers_count' => $valid->pluck('supplier.id')->filter()->unique()->count(),
            ],
        ];
    }

    private function rows(int $productId, ?int $stockId)
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchase_items.product_id', $productId)
            ->when($stockId, fn ($q) => $q->where('purchase_items.stock_id', $stockId))
            ->where('purchases.status', '!=', PurchaseStatus::ANNULE->value)
            ->where('purchase_items.unit_price', '>', 0)
            ->get([
                'purchase_items.purchase_id',
                'purchase_items.unit_price',
                'purchase_items.quantity',
                'purchases.date',
                'suppliers.name as supplier',
            ]);
    }
}
