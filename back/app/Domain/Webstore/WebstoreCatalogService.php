<?php

namespace App\Domain\Webstore;

use App\Domain\Products\ProductReferenceService;
use App\Models\Product;
use App\Models\WebstoreProduct;
use App\Models\WebstoreSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Boutique en ligne — step 1 (read only): connection settings, reading of the
 * WooCommerce catalogue and its matching with the POS catalogue by SKU
 * (WooCommerce SKU = POS product reference). Nothing is written to the site.
 */
class WebstoreCatalogService
{
    /** Safety cap on the catalogue read: 200 pages × 100 products. */
    public const MAX_PAGES = 200;

    /** POS products that could go to the site (services are not sold online). */
    public const POS_TYPES = ['tyre', 'part'];

    public const STATUSES = [WebstoreProduct::MATCHED, WebstoreProduct::SITE_ONLY, WebstoreProduct::NO_SKU, WebstoreProduct::DUPLICATE, 'pos_only'];

    public function __construct(private WooCommerceClient $client) {}

    // ── Settings ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $s = WebstoreSetting::current();

        return [
            'store_url' => $s->store_url,
            'has_credentials' => $s->hasCredentials(),
            'consumer_key_hint' => $s->consumer_key_hint,
            'margin_percent' => $s->margin_percent,
            'create_status' => $s->create_status ?? 'draft',
            'auto_prepare' => (bool) $s->auto_prepare,
            'last_publish_at' => $s->last_publish_at?->toIso8601String(),
            'last_pull_at' => $s->last_pull_at?->toIso8601String(),
            'last_pull_summary' => $s->last_pull_summary,
        ];
    }

    /**
     * Empty key / secret = keep the saved ones. A new shop address forgets the
     * catalogue read from the previous one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateSettings(array $data): array
    {
        $s = WebstoreSetting::current();

        if (array_key_exists('store_url', $data)) {
            $url = filled($data['store_url']) ? WooCommerceClient::normalizeUrl((string) $data['store_url']) : null;
            if ($url !== null) {
                $this->client->assertSafe($url);
            }
            if ($url !== $s->store_url) {
                $this->forgetCatalogue($s);
            }
            $s->store_url = $url;
        }
        if (! empty($data['clear_credentials'])) {
            $s->consumer_key = null;
            $s->consumer_secret = null;
            $s->consumer_key_hint = null;
        }
        if (filled($data['consumer_key'] ?? null)) {
            $key = trim((string) $data['consumer_key']);
            $s->consumer_key = $key;
            $s->consumer_key_hint = mb_substr($key, -4);
        }
        if (filled($data['consumer_secret'] ?? null)) {
            $s->consumer_secret = trim((string) $data['consumer_secret']);
        }
        if (array_key_exists('margin_percent', $data)) {
            $s->margin_percent = $data['margin_percent'] === null || $data['margin_percent'] === '' ? null : round((float) $data['margin_percent'], 2);
        }
        if (array_key_exists('auto_prepare', $data)) {
            $s->auto_prepare = (bool) $data['auto_prepare'];
        }
        if (! empty($data['create_status'])) {
            $s->create_status = $data['create_status'];
        }
        $s->save();

        return $this->settings();
    }

    /** @return array{ok: true, products_total: int} */
    public function testConnection(): array
    {
        return ['ok' => true] + $this->client->test(WebstoreSetting::current());
    }

    // ── Catalogue read ───────────────────────────────────────────────────────

    /**
     * Reads every product of the site, replaces the local copy and matches it.
     *
     * @return array<string, mixed> the summary
     */
    public function pull(): array
    {
        $settings = WebstoreSetting::current();
        @set_time_limit(600);

        $rows = [];
        $page = 1;
        do {
            $result = $this->client->products($settings, $page);
            if ($result['total_pages'] > self::MAX_PAGES) {
                throw ValidationException::withMessages(['store_url' => ['Catalogue trop grand : plus de '.(self::MAX_PAGES * WooCommerceClient::PER_PAGE).' produits.']]);
            }
            foreach ($result['items'] as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $rows[(int) $item['id']] = $this->row($item);
                }
            }
            $page++;
        } while ($page <= $result['total_pages']);

        $rows = $this->match(array_values($rows));

        DB::transaction(function () use ($rows) {
            WebstoreProduct::query()->delete();
            $now = now();
            foreach (array_chunk($rows, 500) as $chunk) {
                WebstoreProduct::query()->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $chunk));
            }
        });

        $summary = $this->summary();
        $settings->forceFill(['last_pull_at' => now(), 'last_pull_summary' => $summary])->save();

        return ['last_pull_at' => $settings->last_pull_at->toIso8601String()] + $summary;
    }

    /**
     * @param  array<string, mixed>  $item  WooCommerce product
     * @return array<string, mixed>
     */
    public function row(array $item): array
    {
        $sku = trim((string) ($item['sku'] ?? ''));
        $money = fn ($v) => is_numeric($v) ? round((float) $v, 2) : null;
        $modified = $item['date_modified_gmt'] ?? null;

        return [
            'woo_id' => (int) $item['id'],
            'sku' => $sku !== '' ? mb_substr($sku, 0, 255) : null,
            'normalized_sku' => $sku !== '' ? mb_substr(ProductReferenceService::normalize($sku), 0, 255) : null,
            'name' => mb_substr(html_entity_decode(strip_tags((string) ($item['name'] ?? '')), ENT_QUOTES | ENT_HTML5) ?: '(sans nom)', 0, 255),
            'type' => isset($item['type']) ? mb_substr((string) $item['type'], 0, 20) : null,
            'status' => isset($item['status']) ? mb_substr((string) $item['status'], 0, 20) : null,
            'price' => $money($item['price'] ?? null),
            'regular_price' => $money($item['regular_price'] ?? null),
            'sale_price' => $money($item['sale_price'] ?? null),
            'stock_status' => isset($item['stock_status']) ? mb_substr((string) $item['stock_status'], 0, 20) : null,
            'stock_quantity' => is_numeric($item['stock_quantity'] ?? null) ? (int) $item['stock_quantity'] : null,
            'images_count' => is_array($item['images'] ?? null) ? min(65535, count($item['images'])) : 0,
            'permalink' => $this->safeLink($item['permalink'] ?? null),
            'woo_modified_at' => $modified ? Carbon::parse($modified, 'UTC') : null,
        ];
    }

    /** Only http(s) links are kept (the page renders them as <a href>). */
    private function safeLink(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        return preg_match('#^https?://#i', $url) ? mb_substr($url, 0, 500) : null;
    }

    /**
     * SKU matching: WooCommerce SKU (normalised) = POS reference.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function match(array $rows): array
    {
        $products = [];
        foreach (Product::query()->pluck('id', 'reference') as $reference => $id) {
            $products[ProductReferenceService::normalize((string) $reference)] = (int) $id;
        }
        $skuCounts = array_count_values(array_filter(array_column($rows, 'normalized_sku')));

        foreach ($rows as &$row) {
            $sku = $row['normalized_sku'];
            $row['product_id'] = $sku !== null ? ($products[$sku] ?? null) : null;
            $row['match_status'] = match (true) {
                $sku === null || $sku === '' => WebstoreProduct::NO_SKU,
                ($skuCounts[$sku] ?? 0) > 1 => WebstoreProduct::DUPLICATE,
                $row['product_id'] !== null => WebstoreProduct::MATCHED,
                default => WebstoreProduct::SITE_ONLY,
            };
        }

        return $rows;
    }

    private function forgetCatalogue(WebstoreSetting $settings): void
    {
        WebstoreProduct::query()->delete();
        $settings->last_pull_at = null;
        $settings->last_pull_summary = null;
    }

    // ── Report ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $counts = WebstoreProduct::query()->selectRaw('match_status, COUNT(*) as n')->groupBy('match_status')->pluck('n', 'match_status');
        $settings = WebstoreSetting::current();

        return [
            'site_total' => (int) $counts->sum(),
            'matched' => (int) ($counts[WebstoreProduct::MATCHED] ?? 0),
            'site_only' => (int) ($counts[WebstoreProduct::SITE_ONLY] ?? 0),
            'no_sku' => (int) ($counts[WebstoreProduct::NO_SKU] ?? 0),
            'duplicate' => (int) ($counts[WebstoreProduct::DUPLICATE] ?? 0),
            'pos_only' => $this->posOnlyQuery()->count(),
            'variable' => WebstoreProduct::query()->where('type', 'variable')->count(),
            'store_url' => $settings->store_url,
            'last_pull_at' => $settings->last_pull_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  status, search, page, per_page
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $status = $filters['status'] ?? WebstoreProduct::MATCHED;
        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $search = trim((string) ($filters['search'] ?? ''));
        $like = '%'.addcslashes($search, '%_\\').'%';

        if ($status === 'pos_only') {
            $query = $this->posOnlyQuery()->with(['brand:id,name', 'tyre'])->orderBy('reference');
            if ($search !== '') {
                $query->where(fn ($q) => $q->where('reference', 'like', $like)->orWhere('profile', 'like', $like));
            }
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            $products = collect($paginator->items());
            $rows = $products->map(fn (Product $p) => [
                'match_status' => 'pos_only',
                'woo' => null,
                'product' => $this->productInfo($p),
            ]);
        } else {
            $query = WebstoreProduct::query()->where('match_status', $status)->with(['product.brand:id,name', 'product.tyre'])
                ->orderBy($status === WebstoreProduct::DUPLICATE ? 'normalized_sku' : 'name')->orderBy('woo_id');
            if ($search !== '') {
                $query->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like));
            }
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            $products = collect($paginator->items())->pluck('product')->filter();
            $rows = collect($paginator->items())->map(fn (WebstoreProduct $w) => [
                'match_status' => $w->match_status,
                'woo' => [
                    'id' => $w->woo_id,
                    'sku' => $w->sku,
                    'name' => $w->name,
                    'type' => $w->type,
                    'status' => $w->status,
                    'price' => $w->price,
                    'regular_price' => $w->regular_price,
                    'sale_price' => $w->sale_price,
                    'stock_status' => $w->stock_status,
                    'stock_quantity' => $w->stock_quantity,
                    'images_count' => $w->images_count,
                    'permalink' => $w->permalink,
                ],
                'product' => $w->product ? $this->productInfo($w->product) : null,
            ]);
        }

        $rows = $this->withPosFigures($rows->all(), $products->pluck('id')->all());

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => $this->summary(),
        ];
    }

    public function posOnlyQuery()
    {
        return Product::query()
            ->where('is_active', true)
            ->whereIn('type', self::POS_TYPES)
            ->whereNotIn('id', WebstoreProduct::query()->whereNotNull('product_id')->select('product_id'));
    }

    /** @return array<string, mixed> */
    public function productInfo(Product $p): array
    {
        $t = $p->tyre;
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $dimension = $t && $t->tire_width ? $num($t->tire_width).'/'.$num($t->tire_height).'R'.$num($t->tire_diameter) : null;
        $label = trim(implode(' ', array_filter([$p->brand?->name, $p->profile, $dimension, $t ? trim(($t->tire_load_index ?? '').($t->tire_speed_index ?? '')) : null])));

        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'type' => $p->type,
            'is_active' => (bool) $p->is_active,
            'label' => $label !== '' ? $label : $p->reference,
        ];
    }

    /**
     * Adds the POS figures useful before publishing: shop stock, photos, supplier offers.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    private function withPosFigures(array $rows, array $productIds): array
    {
        if ($productIds === []) {
            return $rows;
        }
        $stock = DB::table('stocks')->whereIn('product_id', $productIds)->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as qty')->pluck('qty', 'product_id');
        $images = DB::table('product_images')->whereIn('product_id', $productIds)->groupBy('product_id')->selectRaw('product_id, COUNT(*) as n')->pluck('n', 'product_id');
        $offers = DB::table('supplier_price_items as i')
            ->join('supplier_price_files as f', 'f.id', '=', 'i.file_id')
            ->where('f.is_active', true)
            ->whereIn('i.product_id', $productIds)
            ->groupBy('i.product_id')
            ->selectRaw('i.product_id, COUNT(*) as n, MIN(i.price) as best')
            ->get()->keyBy('product_id');

        foreach ($rows as &$row) {
            if ($row['product'] === null) {
                continue;
            }
            $id = $row['product']['id'];
            $row['product']['stock'] = (int) ($stock[$id] ?? 0);
            $row['product']['images_count'] = (int) ($images[$id] ?? 0);
            $row['product']['offers_count'] = (int) ($offers[$id]->n ?? 0);
            $row['product']['best_offer_ttc'] = isset($offers[$id]) ? round((float) $offers[$id]->best, 2) : null;
        }

        return $rows;
    }
}
