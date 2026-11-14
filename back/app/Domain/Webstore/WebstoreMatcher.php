<?php

namespace App\Domain\Webstore;

use App\Domain\Products\ProductReferenceService;
use App\Domain\Products\TyreDescriptionParser;
use App\Domain\SupplierPrices\SupplierProductMatcher;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\Product;
use App\Models\User;
use App\Models\WebstoreLink;
use App\Models\WebstoreProduct;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anti-duplication between the POS and the shop:
 * 1. a product already linked stays linked by the site's product id (WebstoreLink),
 *    whatever its SKU — a SKU to correct is then proposed, never a creation;
 * 2. otherwise the SKU (normalised) = POS reference; the same article twice on
 *    the site is flagged as a duplicate;
 * 3. a site product with an unknown / empty SKU is read like a price-list line
 *    (brand, dimension, indexes, profile) to suggest the POS article it looks
 *    like — that article is then never proposed in creation;
 * 4. an association can be made / undone by hand.
 */
class WebstoreMatcher
{
    public function __construct(
        private TyreDescriptionParser $parser,
        private SupplierProductMatcher $productMatcher,
        private ProductReferenceService $references,
        private ActivityLogService $activityLog,
    ) {}

    /**
     * Sets product_id, match_status and suggested_product_id on the rows read from the site.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function match(array $rows): array
    {
        $links = WebstoreLink::query()->pluck('product_id', 'woo_id')->map(fn ($id) => (int) $id)->all();
        $linkedProducts = array_flip($links); // product_id → woo_id
        $siteIds = array_flip(array_column($rows, 'woo_id'));

        $products = [];
        foreach (Product::query()->pluck('id', 'reference') as $reference => $id) {
            $products[ProductReferenceService::normalize((string) $reference)] = (int) $id;
        }
        $skuCounts = array_count_values(array_filter(array_column($rows, 'normalized_sku')));

        foreach ($rows as &$row) {
            $row['suggested_product_id'] = null;
            $wooId = $row['woo_id'];
            if (isset($links[$wooId])) {
                $row['product_id'] = $links[$wooId];
                $row['match_status'] = WebstoreProduct::MATCHED;

                continue;
            }

            $sku = $row['normalized_sku'];
            $productId = $sku ? ($products[$sku] ?? null) : null;
            // The article is already linked to another product that is still on the site.
            $linkedElsewhere = $productId !== null && isset($linkedProducts[$productId]) && isset($siteIds[$linkedProducts[$productId]]);

            $row['product_id'] = $linkedElsewhere ? null : $productId;
            $row['match_status'] = match (true) {
                $sku === null || $sku === '' => WebstoreProduct::NO_SKU,
                $linkedElsewhere, ($skuCounts[$sku] ?? 0) > 1 => WebstoreProduct::DUPLICATE,
                $productId !== null => WebstoreProduct::MATCHED,
                default => WebstoreProduct::SITE_ONLY,
            };
            if ($linkedElsewhere) {
                $row['suggested_product_id'] = $productId;
            }
        }
        unset($row);

        return $this->suggest($rows);
    }

    /**
     * Site products not matched: their name is read like a price-list line to
     * find the POS tyre they stand for.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function suggest(array $rows): array
    {
        $todo = array_keys(array_filter($rows, fn ($r) => in_array($r['match_status'], [WebstoreProduct::SITE_ONLY, WebstoreProduct::NO_SKU], true)));
        if ($todo === []) {
            return $rows;
        }

        $brands = Brand::query()->get(['id', 'name', 'code'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->all();
        $profiles = [];
        foreach (BrandProfile::query()->get(['brand_id', 'name']) as $profile) {
            $profiles[$profile->brand_id][] = $profile->name;
        }

        $items = $this->references->withCache(function () use ($todo, $rows, $brands, $profiles) {
            $items = [];
            foreach ($todo as $i) {
                $parsed = $this->parser->parse((string) $rows[$i]['name'], $brands, $profiles);
                if (empty($parsed['width']) || empty($parsed['diameter']) || empty($parsed['brand_id'])) {
                    continue;
                }
                $items[$i] = [
                    'match_key' => $this->references->baseReference([
                        'type' => 'tyre',
                        'brand_id' => $parsed['brand_id'],
                        'profile' => $parsed['profile'] ?? null,
                        'tire_width' => $parsed['width'],
                        'tire_height' => $parsed['height'] ?? null,
                        'tire_diameter' => $parsed['diameter'],
                        'tire_load_index' => $parsed['load_index'] ?? null,
                        'tire_speed_index' => $parsed['speed_index'] ?? null,
                        'tire_runflat' => $parsed['runflat'] ?? false,
                    ]),
                    'brand_id' => $parsed['brand_id'],
                    'profile' => $parsed['profile'] ?? null,
                    'width' => $parsed['width'],
                    'height' => $parsed['height'] ?? null,
                    'diameter' => $parsed['diameter'],
                    'load_index' => $parsed['load_index'] ?? null,
                    'speed_index' => $parsed['speed_index'] ?? null,
                ];
            }

            return $items;
        });

        if ($items !== []) {
            $found = $this->productMatcher->link(array_values($items));
            foreach (array_keys($items) as $n => $i) {
                $rows[$i]['suggested_product_id'] = $found[$n]['product_id'] ?? null;
            }
        }

        return $rows;
    }

    /**
     * After a catalogue read: keeps a link for every product matched by SKU and
     * forgets the links of products no longer on the site.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncLinks(array $rows): void
    {
        $siteIds = array_column($rows, 'woo_id');
        DB::transaction(function () use ($rows, $siteIds) {
            WebstoreLink::query()->whereNotIn('woo_id', $siteIds ?: [0])->delete();
            $linked = WebstoreLink::query()->pluck('woo_id', 'product_id');
            foreach ($rows as $row) {
                if ($row['match_status'] !== WebstoreProduct::MATCHED || ! $row['product_id'] || isset($linked[$row['product_id']])) {
                    continue;
                }
                WebstoreLink::query()->updateOrCreate(['woo_id' => $row['woo_id']], ['product_id' => $row['product_id'], 'source' => WebstoreLink::BY_SKU]);
                $linked[$row['product_id']] = $row['woo_id'];
            }
        });
    }

    /** A product created on the site by the POS is linked at once. */
    public function linkCreated(int $productId, int $wooId): void
    {
        WebstoreLink::query()->where('product_id', $productId)->orWhere('woo_id', $wooId)->delete();
        WebstoreLink::query()->create(['product_id' => $productId, 'woo_id' => $wooId, 'source' => WebstoreLink::CREATED]);
    }

    /**
     * Associates a site product with a POS article by hand. If its SKU differs
     * from the POS reference, the next proposals offer to correct it on the site.
     */
    public function link(int $wooId, int $productId, ?User $user): WebstoreProduct
    {
        $site = WebstoreProduct::query()->where('woo_id', $wooId)->first();
        if (! $site) {
            throw ValidationException::withMessages(['woo_id' => ['Produit du site introuvable : relisez le catalogue.']]);
        }
        $product = Product::query()->find($productId);
        if (! $product || $product->type === 'service') {
            throw ValidationException::withMessages(['product_id' => ['Article introuvable (les services ne sont pas vendus en ligne).']]);
        }
        $other = WebstoreLink::query()->where('product_id', $productId)->where('woo_id', '!=', $wooId)->first();
        if ($other && WebstoreProduct::query()->where('woo_id', $other->woo_id)->exists()) {
            $name = WebstoreProduct::query()->where('woo_id', $other->woo_id)->value('name');
            throw ValidationException::withMessages(['product_id' => ["Cet article est déjà relié au produit du site « {$name} » (#{$other->woo_id}) : dissociez-le d'abord, ou supprimez ce doublon sur le site."]]);
        }

        DB::transaction(function () use ($site, $product, $wooId, $user) {
            WebstoreLink::query()->where('product_id', $product->id)->orWhere('woo_id', $wooId)->delete();
            WebstoreLink::query()->create([
                'product_id' => $product->id, 'woo_id' => $wooId, 'source' => WebstoreLink::MANUAL,
                'created_by' => $user?->id, 'created_by_name' => $user?->name,
            ]);
            // Other site rows suggesting / matching this article become duplicates of it.
            WebstoreProduct::query()->where('woo_id', '!=', $wooId)->where('product_id', $product->id)
                ->update(['product_id' => null, 'match_status' => WebstoreProduct::DUPLICATE, 'suggested_product_id' => $product->id]);
            $site->forceFill(['product_id' => $product->id, 'match_status' => WebstoreProduct::MATCHED, 'suggested_product_id' => null])->save();
        });

        $this->activityLog->logWebstoreDecision(
            $wooId,
            "produit du site « {$site->name} » (#{$wooId}) associé à l'article {$product->reference}".(ProductReferenceService::normalize($site->sku) !== ProductReferenceService::normalize($product->reference) ? ' — SKU à corriger sur le site' : ''),
            ['decision' => 'link', 'woo_id' => $wooId, 'product_id' => $product->id, 'site_sku' => $site->sku, 'reference' => $product->reference],
            $user?->id,
            $user?->name,
            "Boutique en ligne — produit du site #{$wooId}",
        );

        return $site->fresh();
    }

    /** Undoes an association: the site product goes back to "unknown" until the next read. */
    public function unlink(int $wooId, ?User $user): void
    {
        $link = WebstoreLink::query()->where('woo_id', $wooId)->first();
        $site = WebstoreProduct::query()->where('woo_id', $wooId)->first();
        if (! $link && ! $site?->product_id) {
            throw ValidationException::withMessages(['woo_id' => ['Ce produit du site n\'est relié à aucun article.']]);
        }
        $link?->delete();
        $site?->forceFill([
            'product_id' => null,
            'match_status' => $site->normalized_sku ? WebstoreProduct::SITE_ONLY : WebstoreProduct::NO_SKU,
        ])->save();

        $this->activityLog->logWebstoreDecision(
            $wooId,
            'produit du site « '.($site->name ?? '#'.$wooId)." » (#{$wooId}) dissocié",
            ['decision' => 'unlink', 'woo_id' => $wooId, 'product_id' => $link?->product_id],
            $user?->id,
            $user?->name,
            "Boutique en ligne — produit du site #{$wooId}",
        );
    }
}
