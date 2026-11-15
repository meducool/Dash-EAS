<?php

namespace App\Console\Commands;

use App\Enums\PurchaseStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives a purchase price to the stock lots that have none (null or 0), from
 * their purchase history — same rule as a new purchase: a lot in stock (1 or
 * more) takes the HIGHEST price paid, an empty lot the LAST one. The lot's own
 * purchases first, else the product's. A lot that already has a price is
 * never touched. Preview by default; --apply writes.
 */
class FillStockPurchasePricesCommand extends Command
{
    protected $signature = 'stocks:fill-purchase-prices
        {--apply : écrit les prix (sinon simple aperçu)}
        {--csv= : chemin d\'un fichier CSV pour l\'aperçu}';

    protected $description = 'Remplit le prix d\'achat des lots de stock sans prix depuis l\'historique des achats (aperçu par défaut)';

    public function handle(): int
    {
        $lots = DB::table('stocks')
            ->leftJoin('products', 'products.id', '=', 'stocks.product_id')
            ->where(fn ($q) => $q->whereNull('stocks.purchase_price')->orWhere('stocks.purchase_price', 0))
            ->orderBy('stocks.id')
            ->get(['stocks.id', 'stocks.product_id', 'stocks.quantity', 'stocks.depot', 'products.reference']);

        $lines = DB::table('purchase_items as pi')
            ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('p.status', '!=', PurchaseStatus::ANNULE->value)
            ->where('pi.unit_price', '>', 0)
            ->where(fn ($q) => $q->whereIn('pi.stock_id', $lots->pluck('id'))->orWhereIn('pi.product_id', $lots->pluck('product_id')))
            ->orderBy('p.date')
            ->orderBy('p.id')
            ->get(['pi.stock_id', 'pi.product_id', 'pi.unit_price', 'p.date', 'p.id as purchase_id']);
        $byLot = $lines->whereNotNull('stock_id')->groupBy('stock_id');
        $byProduct = $lines->groupBy('product_id');

        $changes = [];
        $skipped = 0;
        foreach ($lots as $lot) {
            $history = $byLot->get($lot->id) ?? $byProduct->get($lot->product_id);
            if (! $history || $history->isEmpty()) {
                $skipped++;

                continue;
            }
            $inStock = (int) $lot->quantity >= 1;
            $price = $inStock ? (float) $history->max('unit_price') : (float) $history->last()->unit_price;
            $changes[] = [
                'id' => $lot->id,
                'reference' => $lot->reference,
                'depot' => $lot->depot,
                'quantity' => (int) $lot->quantity,
                'price' => round($price, 2),
                'rule' => ($inStock ? 'Plus haut prix' : 'Dernier prix').($byLot->has($lot->id) ? ' (achats du lot)' : ' (achats du produit)'),
                'purchases' => $history->count(),
            ];
        }

        if ($path = $this->option('csv')) {
            $out = fopen($path, 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Lot', 'Référence', 'Dépôt', 'Quantité', 'Prix d\'achat proposé', 'Règle', 'Achats trouvés'], ';');
            foreach ($changes as $c) {
                fputcsv($out, [$c['id'], $c['reference'], $c['depot'], $c['quantity'], number_format($c['price'], 2, ',', ''), $c['rule'], $c['purchases']], ';');
            }
            fclose($out);
        }

        $this->info(count($changes).' lot(s) à remplir · '.$skipped.' sans aucun achat (laissés sans prix).');
        if (! $this->option('apply')) {
            $this->line('Aperçu seulement : rien n\'a été écrit. Relancez avec --apply pour enregistrer.');

            return self::SUCCESS;
        }

        $written = 0;
        DB::transaction(function () use ($changes, &$written) {
            foreach ($changes as $c) {
                // Still without price (never overwrite one set meanwhile).
                $written += DB::table('stocks')->where('id', $c['id'])
                    ->where(fn ($q) => $q->whereNull('purchase_price')->orWhere('purchase_price', 0))
                    ->update(['purchase_price' => $c['price'], 'updated_at' => now()]);
            }
        });
        $this->info("{$written} prix d'achat enregistré(s).");

        return self::SUCCESS;
    }
}
