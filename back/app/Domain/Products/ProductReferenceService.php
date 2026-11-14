<?php

namespace App\Domain\Products;

use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\Product;

/**
 * Builds the reference (SKU) of a new product, following the shop's rule:
 *
 *   tyre    : {brand code}{width}{height}{diameter}{load}{speed}[RF]-{profile code}
 *             GITI 205/60R16 92H SYNERGY        → GT205601692H-SYN
 *             CONTINENTAL 225/40R19 89Y RFT S5  → C225401989YRF-S5
 *   part    : P + 7-digit product id              → P0000123
 *   service : S + 7-digit product id              → S0000045
 *
 * The result is upper-case, without spaces, and made unique ("-2", "-3"…).
 */
class ProductReferenceService
{
    /**
     * @param  array<string, mixed>  $data  product payload (type, brand_id, profile, tire_*)
     * @param  int|null  $productId  the product's id once created (parts / services, fallbacks)
     * @param  int|null  $exceptId  product to ignore in the uniqueness check
     */
    public function generate(array $data, ?int $productId = null, ?int $exceptId = null): string
    {
        return $this->unique($this->base($data, $productId), $exceptId);
    }

    /**
     * Lookups memoised while {@see withCache()} runs (bulk use: price-list imports).
     *
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    /**
     * The reference a tyre with these characteristics would get, before the
     * uniqueness suffix — null when it cannot be built (dimensions unknown, not
     * a tyre). Used as a matching key between price-list lines and products.
     *
     * @param  array<string, mixed>  $data
     */
    public function baseReference(array $data): ?string
    {
        if (($data['type'] ?? 'tyre') !== 'tyre') {
            return null;
        }
        $base = $this->base($data, null);

        return str_starts_with($base, 'T') && str_ends_with($base, 'XXXXXXX') ? null : $base;
    }

    /**
     * Runs $callback with brand / profile lookups memoised (many references in a row).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withCache(callable $callback): mixed
    {
        $previous = $this->cache;
        $this->cache = $previous ?? [];
        try {
            return $callback();
        } finally {
            $this->cache = $previous;
        }
    }

    private function remember(string $key, callable $resolve): mixed
    {
        if ($this->cache === null) {
            return $resolve();
        }
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $resolve();
        }

        return $this->cache[$key];
    }

    /** Upper-case, trimmed, no spaces — the shape of every reference. */
    public static function normalize(?string $reference): string
    {
        return strtoupper(preg_replace('/\s+/u', '', trim((string) $reference)));
    }

    private function base(array $data, ?int $productId): string
    {
        $type = $data['type'] ?? 'tyre';
        $sequence = $productId !== null ? str_pad((string) $productId, 7, '0', STR_PAD_LEFT) : 'XXXXXXX';

        if ($type === 'service') {
            return 'S'.$sequence;
        }
        if ($type !== 'tyre') {
            return 'P'.$sequence;
        }

        $width = $this->number($data['tire_width'] ?? null);
        $height = $this->number($data['tire_height'] ?? null);
        $diameter = $this->number($data['tire_diameter'] ?? null);
        if ($width === '' || $diameter === '') {
            return 'T'.$sequence; // dimensions unknown: numbered tyre reference
        }

        $brand = ! empty($data['brand_id'])
            ? $this->remember('brand:'.$data['brand_id'], fn () => Brand::find($data['brand_id']))
            : null;
        $brandCode = $brand ? $this->brandCode($brand) : '';

        $reference = $brandCode.$width.$height.$diameter
            .self::normalize($data['tire_load_index'] ?? '')
            .self::normalize($data['tire_speed_index'] ?? '')
            .(! empty($data['tire_runflat']) ? 'RF' : '');

        $profileCode = $this->profileCode($brand, $data['profile'] ?? null);

        return self::normalize($reference.($profileCode !== '' ? '-'.$profileCode : ''));
    }

    /** 205 → "205", 17.5 → "175", 10.50 → "105". */
    private function number(mixed $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return '';
        }
        $text = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return str_replace('.', '', $text);
    }

    private function brandCode(Brand $brand): string
    {
        $code = self::normalize($brand->code);
        if ($code !== '') {
            return $code;
        }

        // No code yet: first letter of the brand name (the shop's usual habit).
        return strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $brand->name), 0, 1));
    }

    private function profileCode(?Brand $brand, ?string $profile): string
    {
        $profile = trim((string) $profile);
        if ($profile === '') {
            return '';
        }

        if ($brand) {
            $known = $this->remember('profile:'.$brand->id.':'.mb_strtolower($profile), fn () => BrandProfile::query()
                ->where('brand_id', $brand->id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($profile)])
                ->value('code'));
            if (self::normalize($known) !== '') {
                return self::normalize($known);
            }
        }

        // No code yet: initials of the words, numbers kept whole — "Pilot Sport 4" → "PS4".
        $code = '';
        foreach (preg_split('/[\s\-\/]+/u', $profile) as $word) {
            if ($word === '') {
                continue;
            }
            $code .= ctype_digit($word) ? $word : strtoupper(mb_substr(preg_replace('/[^\p{L}\d]/u', '', $word), 0, 1));
        }

        return mb_substr(self::normalize($code), 0, 8);
    }

    private function unique(string $base, ?int $exceptId): string
    {
        $candidate = $base;
        $n = 2;
        while ($this->taken($candidate, $exceptId)) {
            $candidate = "{$base}-{$n}";
            $n++;
        }

        return $candidate;
    }

    private function taken(string $reference, ?int $exceptId): bool
    {
        return Product::query()
            ->whereRaw('LOWER(TRIM(reference)) = ?', [mb_strtolower($reference)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }
}
