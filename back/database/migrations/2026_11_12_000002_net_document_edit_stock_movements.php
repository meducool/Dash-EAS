<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cleans up the stock history written before StockMovementService::netted():
 * editing a sale / purchase / service order used to give back every line,
 * then take it again, leaving pairs such as "Annulation achat -28" +
 * "Achat +28" that are not real movements.
 *
 * One save of a document writes its movements within a few seconds (a long
 * document can spill over the next second): movements of the same document
 * less than GAP_SECONDS apart belong to one save. Inside a save, the
 * movements of each stock lot are merged into the single net movement
 * (first quantity → last quantity), or removed when the quantity ends where
 * it started. Stock quantities are not touched — only the history.
 * Data-only and irreversible (deploy.sh backs the database up before migrating).
 */
return new class extends Migration
{
    private const GAP_SECONDS = 5;

    public function up(): void
    {
        DB::table('stock_movements')
            ->whereNotNull('stock_id')
            ->whereNotNull('reference_id')
            ->select('reference_type', 'reference_id')
            ->groupBy('reference_type', 'reference_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(fn ($document) => DB::transaction(fn () => $this->netDocument($document)));
    }

    private function netDocument(object $document): void
    {
        $rows = DB::table('stock_movements')
            ->whereNotNull('stock_id')
            ->where('reference_type', $document->reference_type)
            ->where('reference_id', $document->reference_id)
            ->orderBy('id')
            ->get();

        foreach ($this->saves($rows) as $save) {
            foreach ($save->groupBy('stock_id') as $lotRows) {
                $this->netLot($lotRows->values());
            }
        }
    }

    /**
     * Splits a document's movements (in id order) into saves: a new save
     * starts when more than GAP_SECONDS separate two consecutive movements.
     *
     * @return array<int, Collection>
     */
    private function saves(Collection $rows): array
    {
        $saves = [];
        $current = collect();
        $previous = null;

        foreach ($rows as $row) {
            $at = Carbon::parse($row->created_at);
            if ($previous !== null && $previous->diffInSeconds($at, true) > self::GAP_SECONDS) {
                $saves[] = $current;
                $current = collect();
            }
            $current->push($row);
            $previous = $at;
        }

        if ($current->isNotEmpty()) {
            $saves[] = $current;
        }

        return $saves;
    }

    private function netLot(Collection $rows): void
    {
        if ($rows->count() < 2) {
            return;
        }

        $first = $rows->first();
        $last = $rows->last();
        $delta = (int) $last->quantity_after - (int) $first->quantity_before;
        $ids = $rows->pluck('id');

        if ($delta === 0) {
            DB::table('stock_movements')->whereIn('id', $ids)->delete();

            return;
        }

        // Keep the last movement going the same way as the net change (its type, reason, user).
        $keep = $rows->filter(fn ($row) => ((int) $row->delta > 0) === ($delta > 0))->last() ?? $last;

        DB::table('stock_movements')->where('id', $keep->id)->update([
            'quantity_before' => (int) $first->quantity_before,
            'quantity_after' => (int) $last->quantity_after,
            'delta' => $delta,
        ]);
        DB::table('stock_movements')->whereIn('id', $ids->reject(fn ($id) => $id === $keep->id))->delete();
    }

    public function down(): void
    {
        // Irreversible: the removed pairs are not stored anywhere.
    }
};
