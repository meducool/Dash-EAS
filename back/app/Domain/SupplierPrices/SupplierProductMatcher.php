<?php

namespace App\Domain\SupplierPrices;

use Illuminate\Support\Facades\DB;

/**
 * Finds the catalogue tyre a price-list line stands for: first by the
 * generated reference (same rule as ProductReferenceService), then by
 * characteristics (brand, dimensions, indexes, profile) for products whose
 * reference predates the rule.
 */
class SupplierProductMatcher
{
    /**
     * Fills 'product_id' on each line (rows as inserted in supplier_price_items).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function link(array $items): array
    {
        $keys = array_values(array_unique(array_filter(array_column($items, 'match_key'), fn ($k) => $k && ! str_starts_with($k, 'X-'))));
        $byReference = [];
        foreach (array_chunk($keys, 1000) as $chunk) {
            foreach (DB::table('products')->whereIn('reference', $chunk)->get(['id', 'reference']) as $row) {
                $byReference[strtoupper((string) $row->reference)] = (int) $row->id;
            }
        }

        $brandIds = array_values(array_unique(array_filter(array_column($items, 'brand_id'))));
        $byAttributes = $this->tyresByAttributes($brandIds);

        foreach ($items as &$item) {
            $item['product_id'] = $byReference[$item['match_key'] ?? ''] ?? $this->fromAttributes($item, $byAttributes);
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function find(array $item): ?int
    {
        return $this->link([$item])[0]['product_id'];
    }

    /**
     * @param  array<int, int>  $brandIds
     * @return array<string, array<int, array{id:int, profile:string}>>
     */
    private function tyresByAttributes(array $brandIds): array
    {
        if (! $brandIds) {
            return [];
        }

        $map = [];
        DB::table('products')
            ->join('product_tyres', 'product_tyres.product_id', '=', 'products.id')
            ->where('products.type', 'tyre')
            ->whereIn('products.brand_id', $brandIds)
            ->select('products.id', 'products.brand_id', 'products.profile', 'product_tyres.tire_width', 'product_tyres.tire_height',
                'product_tyres.tire_diameter', 'product_tyres.tire_load_index', 'product_tyres.tire_speed_index')
            ->orderBy('products.id')
            ->each(function ($row) use (&$map) {
                $key = $this->attributeKey($row->brand_id, $row->tire_width, $row->tire_height, $row->tire_diameter, $row->tire_load_index, $row->tire_speed_index);
                $map[$key][] = ['id' => (int) $row->id, 'profile' => mb_strtolower(trim((string) $row->profile))];
            });

        return $map;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<int, array{id:int, profile:string}>>  $map
     */
    private function fromAttributes(array $item, array $map): ?int
    {
        if (empty($item['brand_id']) || empty($item['width']) || empty($item['diameter'])) {
            return null;
        }
        $candidates = $map[$this->attributeKey($item['brand_id'], $item['width'], $item['height'] ?? null, $item['diameter'], $item['load_index'] ?? null, $item['speed_index'] ?? null)] ?? [];
        $profile = mb_strtolower(trim((string) ($item['profile'] ?? '')));
        foreach ($candidates as $candidate) {
            if ($candidate['profile'] === $profile) {
                return $candidate['id'];
            }
        }

        return $profile === '' && count($candidates) === 1 ? $candidates[0]['id'] : null;
    }

    private function attributeKey(mixed $brandId, mixed $width, mixed $height, mixed $diameter, mixed $load, mixed $speed): string
    {
        $n = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        return implode('|', [(int) $brandId, $n($width), $n($height), $n($diameter), strtoupper(trim((string) $load)), strtoupper(trim((string) $speed))]);
    }
}
