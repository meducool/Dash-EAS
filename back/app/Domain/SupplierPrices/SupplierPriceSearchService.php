<?php

namespace App\Domain\SupplierPrices;

use App\Domain\Products\ProductService;
use App\Domain\Settings\CompanySettingsService;
use App\Models\Brand;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\Stock;
use App\Models\SupplierPriceFile;
use App\Models\SupplierPriceItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Searches the active price lists and compares them: lines standing for the
 * same tyre (same generated reference) are grouped, cheapest offer first.
 */
class SupplierPriceSearchService
{
    /** Lines read per search before grouping (beyond that, the user narrows the search). */
    public const MAX_LINES = 3000;

    public function __construct(
        private SupplierProductMatcher $matcher,
        private ProductService $products,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  search, brand_id, brand_name, supplier_id, file_id, page, per_page
     * @return array<string, mixed>
     */
    public function compare(array $filters): array
    {
        $lines = $this->query($filters)
            ->with(['supplier:id,name', 'file:id,name,last_imported_at,margin_percent', 'brand:id,name', 'product:id,reference'])
            ->orderBy('price')
            ->orderBy('id')
            ->limit(self::MAX_LINES + 1)
            ->get();

        $truncated = $lines->count() > self::MAX_LINES;
        $lines = $lines->take(self::MAX_LINES);
        $defaultMargin = $this->defaultMargin();

        $groups = $lines
            ->groupBy(fn (SupplierPriceItem $item) => $item->match_key ?: 'row:'.$item->id)
            ->map(fn ($items, $key) => $this->group((string) $key, $items->values(), $defaultMargin))
            ->sortBy([
                fn ($a, $b) => ($a['diameter'] ?? 999) <=> ($b['diameter'] ?? 999),
                fn ($a, $b) => ($a['width'] ?? 9999) <=> ($b['width'] ?? 9999),
                fn ($a, $b) => ($a['height'] ?? 999) <=> ($b['height'] ?? 999),
                fn ($a, $b) => $a['best_price'] <=> $b['best_price'],
            ])
            ->values();

        $this->attachShopStock($groups);

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $total = $groups->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($lastPage, (int) ($filters['page'] ?? 1)));

        return [
            'data' => $groups->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'lines_count' => $lines->count(),
            'truncated' => $truncated,
            'default_margin' => $defaultMargin,
        ];
    }

    /**
     * Brands found in the active price lists (identified or as typed), with counts.
     *
     * @return array<string, mixed>
     */
    /** Margin (%) applied to a price list without its own: set by the administrator. */
    public function defaultMargin(): float
    {
        return (float) (CompanySetting::query()->value('supplier_price_margin') ?? 0);
    }

    public function setDefaultMargin(float $percent): float
    {
        // The settings row with its defaults when the company profile was never saved.
        $settings = app(CompanySettingsService::class)->get();
        $settings->supplier_price_margin = round($percent, 2);
        $settings->save();

        return $this->defaultMargin();
    }

    /**
     * Selling price = Prix TTC × (1 + margin %).
     *
     * @return array{sale_price: float, margin: float, margin_percent: float}
     */
    public function salePrice(float $price, float $marginPercent): array
    {
        $sale = round($price * (1 + $marginPercent / 100), 2);

        return ['sale_price' => $sale, 'margin' => round($sale - $price, 2), 'margin_percent' => round($marginPercent, 2)];
    }

    /** Speed indexes, slowest to fastest (H sits between U and V). */
    public const SPEED_ORDER = ['L', 'M', 'N', 'P', 'Q', 'R', 'S', 'T', 'U', 'H', 'V', 'W', 'Y', 'Z'];

    /** Km/h of each speed index, for the labels. */
    public const SPEED_KMH = ['L' => 120, 'M' => 130, 'N' => 140, 'P' => 150, 'Q' => 160, 'R' => 170, 'S' => 180, 'T' => 190, 'U' => 200, 'H' => 210, 'V' => 240, 'W' => 270, 'Y' => 300, 'Z' => 240];

    /**
     * @return array<int, string> $speed and every faster index
     */
    public static function speedsFrom(string $speed): array
    {
        $position = array_search($speed, self::SPEED_ORDER, true);

        return $position === false ? [$speed] : array_slice(self::SPEED_ORDER, $position);
    }

    /**
     * Values found in the active price lists, for the comparison filters.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        $base = fn () => DB::table('supplier_price_items')
            ->whereIn('file_id', SupplierPriceFile::query()->where('is_active', true)->select('id'));

        $speeds = $base()->whereNotNull('speed_index')->groupBy('speed_index')
            ->selectRaw('speed_index as value, COUNT(*) as count')->get()
            ->map(fn ($r) => ['value' => $r->value, 'count' => (int) $r->count, 'kmh' => self::SPEED_KMH[$r->value] ?? null])
            ->sortBy(fn ($r) => array_search($r['value'], self::SPEED_ORDER, true) === false ? 99 : array_search($r['value'], self::SPEED_ORDER, true))
            ->values();

        $loads = $base()->whereNotNull('load_index')->groupBy('load_index')
            ->selectRaw('load_index as value, COUNT(*) as count')->get()
            ->map(fn ($r) => ['value' => $r->value, 'count' => (int) $r->count])
            ->sortBy(fn ($r) => (int) $r['value'])
            ->values();

        return [
            'speed_indexes' => $speeds->all(),
            'load_indexes' => $loads->all(),
            'runflat_count' => $base()->where('runflat', true)->count(),
            'reinforced_count' => $base()->where('reinforced', true)->count(),
        ];
    }

    public function brands(): array
    {
        $activeFiles = SupplierPriceFile::query()->where('is_active', true)->pluck('id');

        $rows = DB::table('supplier_price_items')
            ->leftJoin('brands', 'brands.id', '=', 'supplier_price_items.brand_id')
            ->whereIn('supplier_price_items.file_id', $activeFiles)
            ->groupBy('supplier_price_items.brand_id', 'brands.name', DB::raw('CASE WHEN supplier_price_items.brand_id IS NULL THEN supplier_price_items.brand_name END'))
            ->selectRaw('supplier_price_items.brand_id, brands.name as brand, CASE WHEN supplier_price_items.brand_id IS NULL THEN supplier_price_items.brand_name END as raw_name,
                COUNT(*) as items_count, COUNT(DISTINCT supplier_price_items.supplier_id) as suppliers_count,
                MIN(supplier_price_items.price) as min_price, MAX(supplier_price_items.price) as max_price')
            ->get()
            ->map(fn ($row) => [
                'brand_id' => $row->brand_id ? (int) $row->brand_id : null,
                'name' => $row->brand ?? $row->raw_name,
                'identified' => $row->brand_id !== null,
                'items_count' => (int) $row->items_count,
                'suppliers_count' => (int) $row->suppliers_count,
                'min_price' => round((float) $row->min_price, 2),
                'max_price' => round((float) $row->max_price, 2),
            ])
            ->sortBy(fn ($b) => [$b['name'] === null ? 1 : 0, mb_strtolower((string) $b['name'])])
            ->values();

        return [
            'data' => $rows->all(),
            'summary' => [
                'files_count' => $activeFiles->count(),
                'suppliers_count' => SupplierPriceFile::query()->where('is_active', true)->distinct()->count('supplier_id'),
                'items_count' => (int) $rows->sum('items_count'),
                'brands_count' => $rows->whereNotNull('name')->count(),
            ],
        ];
    }

    /**
     * Catalogue product for a price-list line: the existing one (same generated
     * reference, or same characteristics), else a new tyre created from the line.
     * Every line of the same tyre is then linked to it.
     *
     * @return array{product: Product, created: bool}
     */
    public function productFor(SupplierPriceItem $item, ?int $userId): array
    {
        $productId = $item->product_id ?? $this->matcher->find($item->only(['match_key', 'brand_id', 'width', 'height', 'diameter', 'load_index', 'speed_index', 'profile']));
        $created = false;

        if (! $productId) {
            if (! $item->brand_id || ! $item->width || ! $item->diameter) {
                throw ValidationException::withMessages(['item' => ['Marque ou dimensions non identifiées sur cette ligne : créez le produit depuis le catalogue.']]);
            }
            $product = $this->products->create([
                'type' => 'tyre',
                'brand_id' => $item->brand_id,
                'profile' => $item->profile,
                'description' => $item->designation,
                'tire_width' => $item->width,
                'tire_height' => $item->height,
                'tire_diameter' => $item->diameter,
                'tire_load_index' => $item->load_index,
                'tire_speed_index' => $item->speed_index,
                'tire_season' => $item->season,
                'tire_runflat' => $item->runflat,
                'tire_reinforced' => $item->reinforced,
            ], $userId);
            $productId = $product->id;
            $created = true;
        }

        SupplierPriceItem::query()
            ->when($item->match_key, fn ($q) => $q->where('match_key', $item->match_key), fn ($q) => $q->whereKey($item->id))
            ->update(['product_id' => $productId]);

        return ['product' => $this->products->loadRelations(Product::query()->findOrFail($productId)), 'created' => $created];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters): Builder
    {
        $query = SupplierPriceItem::query()
            ->whereIn('file_id', SupplierPriceFile::query()->where('is_active', true)->select('id'));

        foreach (['supplier_id', 'file_id', 'brand_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, (int) $filters[$field]);
            }
        }
        if (! empty($filters['brand_name'])) {
            $query->whereNull('brand_id')->where('brand_name', $filters['brand_name']);
        }
        // Runflat / XL: "1" = only those, "0" = without.
        foreach (['runflat', 'reinforced'] as $flag) {
            if (isset($filters[$flag]) && $filters[$flag] !== '' && $filters[$flag] !== null) {
                $query->where($flag, (bool) (int) $filters[$flag]);
            }
        }
        if (! empty($filters['speed_index'])) {
            $speed = strtoupper((string) $filters['speed_index']);
            // speed_min = that index or faster (a W or Y tyre also fits a car homologated V).
            ! empty($filters['speed_min'])
                ? $query->whereIn('speed_index', self::speedsFrom($speed))
                : $query->where('speed_index', $speed);
        }
        if (! empty($filters['load_index'])) {
            $query->where('load_index', (string) $filters['load_index']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return $query;
        }

        $parsed = Stock::parseSearchQuery($search);
        foreach (['width', 'height', 'diameter'] as $dimension) {
            if ($parsed[$dimension] !== null) {
                $query->where($dimension, $parsed[$dimension]);
            }
        }
        if ($parsed['brand_prefix']) {
            $prefix = $parsed['brand_prefix'];
            $brandIds = Brand::query()
                ->where('code', $prefix)
                ->orWhere('name', 'like', $this->like($prefix).'%')
                ->pluck('id');
            $query->where(fn ($q) => $q->whereIn('brand_id', $brandIds)->orWhere('brand_name', 'like', $this->like($prefix).'%'));
        }

        foreach ($parsed['text'] as $token) {
            if (preg_match('/^(\d{2,3})([A-HJ-NP-WYZ])$/i', $token, $m)) {
                $query->where('load_index', $m[1])->where('speed_index', strtoupper($m[2]));

                continue;
            }
            $like = '%'.$this->like($token).'%';
            $query->where(fn ($q) => $q->where('designation', 'like', $like)
                ->orWhere('supplier_reference', 'like', $like)
                ->orWhere('brand_name', 'like', $like)
                ->orWhere('profile', 'like', $like)
                ->orWhere('match_key', 'like', $like));
        }

        return $query;
    }

    private function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * @param  Collection<int, SupplierPriceItem>  $items  sorted by price
     * @return array<string, mixed>
     */
    private function group(string $key, $items, float $defaultMargin): array
    {
        $first = $items->first();
        $best = (float) $first->price;
        $max = (float) $items->max('price');
        $product = $items->first(fn ($i) => $i->product)?->product;

        return [
            'key' => $key,
            'match_key' => $first->match_key,
            'brand_id' => $first->brand_id,
            'brand' => $first->brand?->name ?? $first->brand_name,
            'profile' => $first->profile,
            'width' => $first->width,
            'height' => $first->height,
            'diameter' => $first->diameter,
            'dimension' => $this->dimensionLabel($first),
            'load_index' => $first->load_index,
            'speed_index' => $first->speed_index,
            'runflat' => $first->runflat,
            'reinforced' => $first->reinforced,
            'season' => $first->season,
            'identified' => $first->width !== null && $first->brand_id !== null,
            'product' => $product ? ['id' => $product->id, 'reference' => $product->reference] : null,
            'best_price' => round($best, 2),
            'max_price' => round($max, 2),
            'spread' => round($max - $best, 2),
            'offers_count' => $items->count(),
            'suppliers_count' => $items->pluck('supplier_id')->unique()->count(),
            'offers' => $items->map(fn (SupplierPriceItem $i) => [
                'id' => $i->id,
                'price' => round((float) $i->price, 2),
                ...$this->salePrice((float) $i->price, $i->file?->margin_percent ?? $defaultMargin),
                'is_best' => abs((float) $i->price - $best) < 0.005,
                'designation' => $i->designation,
                'supplier_reference' => $i->supplier_reference,
                'availability' => $i->availability,
                'supplier' => $i->supplier ? ['id' => $i->supplier->id, 'name' => $i->supplier->name] : null,
                'file' => $i->file ? ['id' => $i->file->id, 'name' => $i->file->name, 'last_imported_at' => $i->file->last_imported_at?->toIso8601String()] : null,
            ])->values()->all(),
        ];
    }

    private function dimensionLabel(SupplierPriceItem $item): ?string
    {
        if ($item->width === null || $item->diameter === null) {
            return null;
        }
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        return $n($item->width).($item->height !== null ? '/'.$n($item->height) : '').'R'.$n($item->diameter);
    }

    /**
     * Quantity in the shop for each matched product.
     *
     * @param  Collection<int, array<string, mixed>>  $groups
     */
    private function attachShopStock($groups): void
    {
        $ids = $groups->pluck('product.id')->filter()->unique()->values();
        $stock = $ids->isEmpty() ? collect() : Stock::query()
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as qty')
            ->pluck('qty', 'product_id');

        $groups->transform(function ($group) use ($stock) {
            if ($group['product']) {
                $group['product']['stock_quantity'] = (int) ($stock[$group['product']['id']] ?? 0);
            }

            return $group;
        });
    }
}
