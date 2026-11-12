<?php

namespace App\Domain\Brands;

use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BrandProfileService
{
    /**
     * @return Collection<int, array{id: int, brand_id: int, name: string, products_count: int}>
     */
    public function list(Brand $brand): Collection
    {
        $counts = Product::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('profile')
            ->selectRaw('TRIM(profile) as name, COUNT(*) as n')
            ->groupByRaw('TRIM(profile)')
            ->pluck('n', 'name')
            ->mapWithKeys(fn ($n, $name) => [mb_strtolower($name) => (int) $n]);

        return $brand->profiles()->orderBy('name')->get()
            ->map(fn (BrandProfile $profile) => $this->present($profile, $counts[mb_strtolower($profile->name)] ?? 0));
    }

    public function create(Brand $brand, string $name, ?string $code = null): array
    {
        $name = $this->clean($name);
        $this->guardUnique($brand->id, $name);

        return $this->present($brand->profiles()->create(['name' => $name, 'code' => $this->cleanCode($code)]), 0);
    }

    /**
     * Renames the profile and the tyre products of this brand that carry the
     * old name, so the catalogue stays consistent with the reference list.
     */
    public function rename(BrandProfile $profile, string $name, ?string $code = null, bool $codeGiven = false): array
    {
        $name = $this->clean($name);
        $this->guardUnique($profile->brand_id, $name, $profile->id);

        $updated = DB::transaction(function () use ($profile, $name, $code, $codeGiven) {
            $updated = Product::query()
                ->where('brand_id', $profile->brand_id)
                ->whereRaw('TRIM(profile) = ?', [$profile->name])
                ->update(['profile' => $name]);

            $profile->update($codeGiven ? ['name' => $name, 'code' => $this->cleanCode($code)] : ['name' => $name]);

            return $updated;
        });

        return $this->present($profile->fresh(), $updated);
    }

    /** Removes the profile from the list; products keep their profile text. */
    public function delete(BrandProfile $profile): void
    {
        $profile->delete();
    }

    private function cleanCode(?string $code): ?string
    {
        $code = strtoupper(preg_replace('/\s+/u', '', trim((string) $code)));

        return $code !== '' ? $code : null;
    }

    private function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    private function guardUnique(int $brandId, string $name, ?int $exceptId = null): void
    {
        $exists = BrandProfile::query()
            ->where('brand_id', $brandId)
            ->where('name', $name)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => ['Ce profil existe déjà pour cette marque.']]);
        }
    }

    /** @return array{id: int, brand_id: int, name: string, code: ?string, products_count: int} */
    private function present(BrandProfile $profile, int $productsCount): array
    {
        return [
            'id' => $profile->id,
            'brand_id' => $profile->brand_id,
            'name' => $profile->name,
            'code' => $profile->code,
            'products_count' => $productsCount,
        ];
    }
}
