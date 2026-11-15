<?php

namespace App\Domain\Webstore;

use App\Domain\Products\ProductReferenceService;
use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\WebstoreBatch;
use App\Models\WebstoreChange;
use App\Models\WebstoreProduct;
use App\Models\WebstoreSetting;
use App\Services\ActivityLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Boutique en ligne — step 2: computes the changes to bring the WooCommerce shop
 * in line with the POS (price from the cheapest supplier offer + shop margin,
 * availability, photos) and records the approver's decisions. Nothing is sent
 * to the site here: publishing (step 3) only takes approved lines.
 */
class WebstoreProposalService
{
    /** A price moving by more than this share of the site price raises an alert. */
    public const PRICE_JUMP = 0.10;

    public const ALERTS = ['price_jump', 'below_cost', 'no_photo', 'site_draft', 'manual_price'];

    /** Statuses an approver can change (published / failed lines are history). */
    private const DECIDABLE = [WebstoreChange::PROPOSED, WebstoreChange::APPROVED, WebstoreChange::REJECTED, WebstoreChange::FAILED];

    public function __construct(
        private WebstoreCatalogService $catalog,
        private ActivityLogService $activityLog,
    ) {}

    // ── Generation ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> the new batch */
    public function generate(?User $user, ?string $byName = null): array
    {
        $settings = WebstoreSetting::current();
        if ($settings->margin_percent === null) {
            throw ValidationException::withMessages(['margin_percent' => ['Renseignez la marge boutique dans « ⚙️ Connexion » avant de calculer les propositions.']]);
        }
        if ($settings->last_pull_at === null) {
            throw ValidationException::withMessages(['catalogue' => ['Lisez d\'abord le catalogue du site (« 🔄 Lire le catalogue du site »).']]);
        }
        $margin = (float) $settings->margin_percent;

        $site = WebstoreProduct::query()
            ->where('match_status', WebstoreProduct::MATCHED)
            ->whereNotNull('product_id')
            ->get()
            ->keyBy('product_id');
        $missing = $this->catalog->posOnlyQuery()->pluck('id');
        $productIds = $site->keys()->merge($missing)->unique()->values()->all();

        $products = Product::query()->with(['brand:id,name', 'tyre'])->whereIn('id', $productIds)->get()->keyBy('id');
        $figures = $this->figures($productIds);

        $lines = [];
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'variable_skipped' => 0, 'no_price_skipped' => 0, 'duplicate_skipped' => 0, 'with_alerts' => 0];
        $suspected = array_flip($this->catalog->suspectedProductIds());
        foreach ($productIds as $id) {
            $product = $products->get($id);
            if (! $product) {
                continue;
            }
            $f = $figures($id);
            $woo = $site->get($id);

            if ($woo && $woo->type === 'variable') {
                $counts['variable_skipped']++;

                continue;
            }
            if (! $woo && isset($suspected[$id])) {
                // Probably already on the site under another SKU: associate it instead of creating a duplicate.
                $counts['duplicate_skipped']++;

                continue;
            }
            $line = $woo ? $this->updateLine($woo, $product, $f, $margin) : $this->createLine($product, $f, $margin);
            if ($line === null) {
                $counts[$woo ? 'unchanged' : 'no_price_skipped']++;

                continue;
            }
            $counts[$line['action']]++;
            if ($line['alerts'] !== []) {
                $counts['with_alerts']++;
            }
            $lines[] = $line + ['product_id' => $id];
        }

        $batch = DB::transaction(function () use ($lines, $counts, $margin, $settings, $user, $byName) {
            $this->supersedeOpenBatches();
            $batch = WebstoreBatch::query()->create([
                'status' => WebstoreBatch::OPEN,
                'margin_percent' => $margin,
                'catalogue_read_at' => $settings->last_pull_at,
                'counts' => $counts,
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? $byName,
            ]);
            $now = now();
            foreach (array_chunk($lines, 300) as $chunk) {
                WebstoreChange::query()->insert(array_map(fn ($l) => [
                    'batch_id' => $batch->id,
                    'product_id' => $l['product_id'],
                    'woo_id' => $l['woo_id'],
                    'action' => $l['action'],
                    'fields' => json_encode($l['fields']),
                    'current' => json_encode($l['current']),
                    'proposed' => json_encode($l['proposed']),
                    'alerts' => json_encode($l['alerts']),
                    'status' => WebstoreChange::PROPOSED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            return $batch;
        });

        return $this->batchInfo($batch);
    }

    /**
     * POS figures for every product at once: shop stock, photos, cheapest active
     * supplier offer (TTC) and highest purchase price (for the "below cost" check).
     *
     * @param  array<int, int>  $ids
     * @return callable(int): array<string, mixed>
     */
    private function figures(array $ids): callable
    {
        $stock = collect();
        $images = collect();
        $offers = collect();
        $costs = collect();
        foreach (array_chunk($ids, 1000) as $chunk) {
            $stock = $stock->union(DB::table('stocks')->whereIn('product_id', $chunk)->groupBy('product_id')
                ->selectRaw('product_id, SUM(quantity) as qty')->pluck('qty', 'product_id'));
            $images = $images->union(DB::table('product_images')->whereIn('product_id', $chunk)
                ->orderBy('position')->orderBy('id')->get(['id', 'product_id', 'path'])->groupBy('product_id'));
            $offers = $offers->union(DB::table('supplier_price_items as i')
                ->join('supplier_price_files as f', 'f.id', '=', 'i.file_id')
                ->where('f.is_active', true)->where('i.price', '>', 0)
                ->whereIn('i.product_id', $chunk)->groupBy('i.product_id')
                ->selectRaw('i.product_id, MIN(i.price) as best, COUNT(*) as n')->get()->keyBy('product_id'));
            $costs = $costs->union(DB::table('purchase_items as pi')
                ->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
                ->where('p.status', '!=', PurchaseStatus::ANNULE->value)->where('pi.unit_price', '>', 0)
                ->whereIn('pi.product_id', $chunk)->groupBy('pi.product_id')
                ->selectRaw('pi.product_id, MAX(pi.unit_price) as cost')->pluck('cost', 'product_id'));
        }

        return fn (int $id) => [
            'stock' => max(0, (int) ($stock[$id] ?? 0)),
            'images' => collect($images[$id] ?? [])->map(fn ($i) => ['id' => (int) $i->id, 'url' => asset('storage/'.ltrim($i->path, '/'))])->values()->all(),
            'best_offer' => isset($offers[$id]) ? round((float) $offers[$id]->best, 2) : null,
            'offers_count' => (int) ($offers[$id]->n ?? 0),
            'cost' => isset($costs[$id]) ? round((float) $costs[$id], 2) : null,
        ];
    }

    /** Site price = cheapest supplier price TTC × (1 + shop margin). */
    private function price(?float $bestOffer, float $margin): ?float
    {
        return $bestOffer === null ? null : round($bestOffer * (1 + $margin / 100), 2);
    }

    /**
     * Availability on the site: in stock when the shop has it, else "on backorder"
     * when a supplier offers it, else out of stock.
     *
     * @param  array<string, mixed>  $f
     * @return array{stock_status: string, stock_quantity: int}
     */
    private function availability(array $f): array
    {
        return match (true) {
            $f['stock'] > 0 => ['stock_status' => 'instock', 'stock_quantity' => $f['stock']],
            $f['offers_count'] > 0 => ['stock_status' => 'onbackorder', 'stock_quantity' => 0],
            default => ['stock_status' => 'outofstock', 'stock_quantity' => 0],
        };
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>|null null = nothing to change
     */
    private function updateLine(WebstoreProduct $woo, Product $product, array $f, float $margin): ?array
    {
        $sitePrice = $woo->regular_price ?? $woo->price;
        $price = $this->price($f['best_offer'], $margin);
        $availability = $this->availability($f);

        $fields = [];
        $proposed = [];
        if ($price !== null && ($sitePrice === null || abs($price - $sitePrice) >= 0.01)) {
            $fields[] = 'price';
            $proposed['price'] = $price;
        }
        $stockChanged = $availability['stock_status'] !== $woo->stock_status
            || ($availability['stock_status'] === 'instock' && $availability['stock_quantity'] !== $woo->stock_quantity);
        if ($stockChanged) {
            $fields[] = 'stock';
            $proposed += $availability;
        }
        // Photos only fill an empty site gallery: photos already on the site are never replaced.
        if ($f['images'] !== [] && $woo->images_count === 0) {
            $fields[] = 'images';
            $proposed['images'] = $f['images'];
        }
        // Linked product whose SKU is not the POS reference: the site SKU is corrected (same product, no duplicate).
        if (ProductReferenceService::normalize($woo->sku) !== ProductReferenceService::normalize($product->reference)) {
            $fields[] = 'sku';
            $proposed['sku'] = $product->reference;
        }
        if ($fields === []) {
            return null;
        }

        $current = [
            'name' => $woo->name,
            'sku' => $woo->sku,
            'status' => $woo->status,
            'price' => $sitePrice,
            'stock_status' => $woo->stock_status,
            'stock_quantity' => $woo->stock_quantity,
            'images_count' => $woo->images_count,
            'pos_stock' => $f['stock'],
            'pos_images' => count($f['images']),
            'best_offer_ttc' => $f['best_offer'],
            'purchase_cost' => $f['cost'],
        ];

        return [
            'action' => WebstoreChange::UPDATE,
            'woo_id' => $woo->woo_id,
            'fields' => $fields,
            'current' => $current,
            'proposed' => $proposed,
            'alerts' => $this->alerts($proposed['price'] ?? null, $current, $woo->status),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>|null null = no supplier offer, hence no price to propose
     */
    private function createLine(Product $product, array $f, float $margin): ?array
    {
        $price = $this->price($f['best_offer'], $margin);
        if ($price === null) {
            return null;
        }
        $info = $this->catalog->productInfo($product);
        $tyre = $product->tyre;
        $current = [
            'pos_stock' => $f['stock'],
            'pos_images' => count($f['images']),
            'best_offer_ttc' => $f['best_offer'],
            'purchase_cost' => $f['cost'],
            'price' => null,
        ];

        return [
            'action' => WebstoreChange::CREATE,
            'woo_id' => null,
            'fields' => array_values(array_filter(['price', 'stock', $f['images'] !== [] ? 'images' : null])),
            'current' => $current,
            'proposed' => [
                'sku' => $product->reference,
                'name' => $this->siteName($product, $info['label']),
                'type' => $product->type,
                'brand' => $product->brand?->name,
                'description' => $product->description,
                'attributes' => array_filter([
                    'Largeur' => $tyre?->tire_width ? (string) (float) $tyre->tire_width : null,
                    'Hauteur' => $tyre?->tire_height ? (string) (float) $tyre->tire_height : null,
                    'Diamètre' => $tyre?->tire_diameter ? (string) (float) $tyre->tire_diameter : null,
                    'Indice de charge' => $tyre?->tire_load_index,
                    'Indice de vitesse' => $tyre?->tire_speed_index,
                    'Saison' => $tyre?->tire_season,
                    'Runflat' => $tyre?->tire_runflat ? 'Oui' : null,
                    'XL' => $tyre?->tire_reinforced ? 'Oui' : null,
                ], fn ($v) => $v !== null && $v !== ''),
                'price' => $price,
                'images' => $f['images'],
            ] + $this->availability($f),
            'alerts' => $this->alerts($price, $current, null),
        ];
    }

    /**
     * Name of a product created on the shop, written like the site's own products:
     * "Pneu Continental EcoContact 6 235/55 R19 101T". Other articles keep the POS label.
     */
    public function siteName(Product $product, string $fallback): string
    {
        $t = $product->tyre;
        if ($product->type !== 'tyre' || ! $t || ! $t->tire_width || ! $t->tire_diameter) {
            return $fallback;
        }
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $dimension = $num($t->tire_width).($t->tire_height ? '/'.$num($t->tire_height) : '').' R'.$num($t->tire_diameter);

        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter([
            'Pneu',
            $this->brandLabel($product->brand?->name),
            $product->profile,
            $dimension,
            trim(($t->tire_load_index ?? '').($t->tire_speed_index ?? '')),
            $t->tire_reinforced ? 'XL' : null,
            $t->tire_runflat ? 'RunFlat' : null,
        ]))));
    }

    /** "CONTINENTAL" → "Continental", "BF GOODRICH" → "BF Goodrich"; a brand already in mixed case is kept. */
    private function brandLabel(?string $brand): ?string
    {
        $brand = trim((string) $brand);
        if ($brand === '' || $brand !== mb_strtoupper($brand)) {
            return $brand ?: null;
        }

        return implode(' ', array_map(
            fn ($word) => mb_strlen($word) <= 3 ? $word : mb_convert_case($word, MB_CASE_TITLE),
            preg_split('/\s+/u', $brand),
        ));
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<int, string>
     */
    private function alerts(?float $price, array $current, ?string $siteStatus): array
    {
        $alerts = [];
        $old = $current['price'] ?? null;
        if ($price !== null && $old !== null && $old > 0 && abs($price - $old) / $old > self::PRICE_JUMP) {
            $alerts[] = 'price_jump';
        }
        $cost = $current['purchase_cost'] ?? null;
        if ($price !== null && $cost !== null && $price < $cost) {
            $alerts[] = 'below_cost';
        }
        if (($current['pos_images'] ?? 0) === 0 && ($current['images_count'] ?? 0) === 0) {
            $alerts[] = 'no_photo';
        }
        if ($siteStatus !== null && $siteStatus !== 'publish') {
            $alerts[] = 'site_draft';
        }

        return $alerts;
    }

    private function supersedeOpenBatches(): void
    {
        $open = WebstoreBatch::query()->where('status', WebstoreBatch::OPEN)->pluck('id');
        if ($open->isEmpty()) {
            return;
        }
        WebstoreChange::query()->whereIn('batch_id', $open)
            ->whereIn('status', [WebstoreChange::PROPOSED, WebstoreChange::APPROVED])
            ->update(['status' => WebstoreChange::SUPERSEDED, 'updated_at' => now()]);
        WebstoreBatch::query()->whereIn('id', $open)->update(['status' => WebstoreBatch::SUPERSEDED, 'updated_at' => now()]);
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function openBatch(): ?WebstoreBatch
    {
        return WebstoreBatch::query()->where('status', WebstoreBatch::OPEN)->latest('id')->first();
    }

    /** @return array<string, mixed> */
    public function batchInfo(?WebstoreBatch $batch): array
    {
        if (! $batch) {
            return ['batch' => null];
        }
        $statuses = $batch->changes()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return ['batch' => [
            'id' => $batch->id,
            'status' => $batch->status,
            'margin_percent' => $batch->margin_percent,
            'catalogue_read_at' => $batch->catalogue_read_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
            'created_by_name' => $batch->created_by_name,
            'counts' => $batch->counts,
            'statuses' => [
                WebstoreChange::PROPOSED => (int) ($statuses[WebstoreChange::PROPOSED] ?? 0),
                WebstoreChange::APPROVED => (int) ($statuses[WebstoreChange::APPROVED] ?? 0),
                WebstoreChange::REJECTED => (int) ($statuses[WebstoreChange::REJECTED] ?? 0),
                WebstoreChange::PUBLISHED => (int) ($statuses[WebstoreChange::PUBLISHED] ?? 0),
                WebstoreChange::FAILED => (int) ($statuses[WebstoreChange::FAILED] ?? 0),
            ],
        ]];
    }

    /**
     * Lines of the open batch.
     *
     * @param  array<string, mixed>  $filters  status, action, alert (none|any|<code>), search, page, per_page
     * @return array<string, mixed>
     */
    public function changes(array $filters): array
    {
        $batch = ! empty($filters['batch_id'])
            ? WebstoreBatch::query()->find((int) $filters['batch_id'])
            : $this->openBatch();
        if (! $batch) {
            return ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0]] + $this->batchInfo(null);
        }

        $query = $batch->changes()->with(['product:id,reference,profile,brand_id,type', 'product.brand:id,name', 'product.tyre']);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        $alert = $filters['alert'] ?? '';
        if ($alert === 'none') {
            $query->whereJsonLength('alerts', 0);
        } elseif ($alert === 'any') {
            $query->whereJsonLength('alerts', '>', 0);
        } elseif (in_array($alert, self::ALERTS, true)) {
            $query->whereJsonContains('alerts', $alert);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('product', fn ($q) => $q->where('reference', 'like', $like)->orWhere('profile', 'like', $like));
        }

        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));
        $paginator = $query->orderBy('action')->orderBy('id')->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $woo = WebstoreProduct::query()->whereIn('woo_id', collect($paginator->items())->pluck('woo_id')->filter())->get(['woo_id', 'name', 'permalink', 'sku'])->keyBy('woo_id');

        $rows = collect($paginator->items())->map(fn (WebstoreChange $c) => [
            'id' => $c->id,
            'action' => $c->action,
            'status' => $c->status,
            'fields' => $c->fields,
            'alerts' => $c->alerts,
            'current' => $c->current,
            'proposed' => $c->proposed,
            'price_override' => $c->price_override,
            'final_price' => $c->finalPrice(),
            'decided_by_name' => $c->decided_by_name,
            'decided_at' => $c->decided_at?->toIso8601String(),
            'published_at' => $c->published_at?->toIso8601String(),
            'error' => $c->error,
            'product' => $c->product ? $this->catalog->productInfo($c->product) : null,
            'woo' => $c->woo_id && $woo->has($c->woo_id) ? [
                'id' => $c->woo_id,
                'name' => $woo[$c->woo_id]->name,
                'sku' => $woo[$c->woo_id]->sku,
                'permalink' => $woo[$c->woo_id]->permalink,
            ] : null,
        ]);

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ] + $this->batchInfo($batch);
    }

    // ── Decisions ────────────────────────────────────────────────────────────

    /**
     * Approves, rejects or puts back to "proposed" lines of the open batch.
     * $scope 'no_alerts' = every proposed line without alert (ids ignored).
     *
     * @param  array<int, int>  $ids
     * @return array<string, mixed>
     */
    public function decide(string $decision, array $ids, string $scope, ?User $user): array
    {
        $batch = $this->openBatch();
        if (! $batch) {
            throw ValidationException::withMessages(['ids' => ['Aucun lot de propositions ouvert.']]);
        }
        [$to, $from] = match ($decision) {
            'approve' => [WebstoreChange::APPROVED, [WebstoreChange::PROPOSED, WebstoreChange::REJECTED, WebstoreChange::FAILED]],
            'reject' => [WebstoreChange::REJECTED, [WebstoreChange::PROPOSED, WebstoreChange::APPROVED, WebstoreChange::FAILED]],
            'reset' => [WebstoreChange::PROPOSED, [WebstoreChange::APPROVED, WebstoreChange::REJECTED, WebstoreChange::FAILED]],
        };

        $query = $batch->changes()->whereIn('status', $from);
        if ($scope === 'no_alerts') {
            $query->where('status', WebstoreChange::PROPOSED)->whereJsonLength('alerts', 0);
        } else {
            $query->whereIn('id', $ids);
        }
        $changed = $query->pluck('id')->all();

        if ($changed !== []) {
            $batch->changes()->whereIn('id', $changed)->update([
                'status' => $to,
                'decided_by' => $to === WebstoreChange::PROPOSED ? null : $user?->id,
                'decided_by_name' => $to === WebstoreChange::PROPOSED ? null : $user?->name,
                'decided_at' => $to === WebstoreChange::PROPOSED ? null : now(),
                'updated_at' => now(),
            ]);
            $label = ['approve' => 'approuvée(s)', 'reject' => 'rejetée(s)', 'reset' => 'remise(s) en attente'][$decision];
            $this->activityLog->logWebstoreDecision(
                $batch->id,
                count($changed).' proposition(s) '.$label." — lot #{$batch->id}",
                ['decision' => $decision, 'ids' => array_slice($changed, 0, 500), 'count' => count($changed)],
                $user?->id,
                $user?->name,
            );
        }

        return ['updated' => count($changed)] + $this->batchInfo($batch);
    }

    /**
     * Price typed by the approver (null = back to the computed price). The line
     * goes back to "proposed": it must be approved again with its new price.
     *
     * @return array<string, mixed>
     */
    public function overridePrice(WebstoreChange $change, ?float $price, ?User $user): array
    {
        $batch = $this->openBatch();
        if (! $batch || $change->batch_id !== $batch->id || ! in_array($change->status, self::DECIDABLE, true)) {
            throw ValidationException::withMessages(['price' => ['Cette proposition ne peut plus être modifiée.']]);
        }
        if ($change->action === WebstoreChange::UPDATE && ! in_array('price', $change->fields, true) && $price !== null) {
            // A price can also be set on a line that only changed stock / photos.
            $change->fields = array_values(array_unique([...$change->fields, 'price']));
        }

        $change->price_override = $price === null ? null : round($price, 2);
        $finalPrice = $change->finalPrice();
        $manual = $change->price_override !== null;
        // Price alerts recomputed on the final price; the other alerts are kept.
        $kept = array_diff($change->alerts, ['price_jump', 'below_cost', 'manual_price']);
        $priceAlerts = array_intersect($this->alerts($finalPrice, $change->current ?? [], null), ['price_jump', 'below_cost']);
        $change->alerts = array_values(array_unique([...$kept, ...$priceAlerts, ...($manual ? ['manual_price'] : [])]));
        $change->status = WebstoreChange::PROPOSED;
        $change->decided_by = null;
        $change->decided_by_name = null;
        $change->decided_at = null;
        $change->save();

        $this->activityLog->logWebstoreDecision(
            $batch->id,
            "Prix modifié à la main pour la proposition #{$change->id} : ".($manual ? number_format((float) $change->price_override, 2, ',', ' ').' DH' : 'prix calculé'),
            ['decision' => 'price', 'id' => $change->id, 'price' => $change->price_override],
            $user?->id,
            $user?->name,
        );

        return ['id' => $change->id, 'status' => $change->status, 'price_override' => $change->price_override, 'final_price' => $finalPrice, 'alerts' => $change->alerts];
    }

    /**
     * Past batches, most recent first, with their line counts by status.
     *
     * @return array<string, mixed>
     */
    public function history(int $page = 1, int $perPage = 20): array
    {
        $paginator = WebstoreBatch::query()->latest('id')->paginate($perPage, ['*'], 'page', max(1, $page));
        $statuses = WebstoreChange::query()
            ->whereIn('batch_id', collect($paginator->items())->pluck('id'))
            ->selectRaw('batch_id, status, COUNT(*) as n')
            ->groupBy('batch_id', 'status')
            ->get()
            ->groupBy('batch_id');

        return [
            'data' => collect($paginator->items())->map(fn (WebstoreBatch $b) => [
                'id' => $b->id,
                'status' => $b->status,
                'margin_percent' => $b->margin_percent,
                'created_at' => $b->created_at?->toIso8601String(),
                'created_by_name' => $b->created_by_name,
                'counts' => $b->counts,
                'statuses' => collect($statuses[$b->id] ?? [])->mapWithKeys(fn ($r) => [$r->status => (int) $r->n])->all(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /** @return Collection<int, WebstoreChange> lines waiting for publishing (step 3) */
    public function approved(): Collection
    {
        return $this->openBatch()?->changes()->where('status', WebstoreChange::APPROVED)->get() ?? collect();
    }
}
