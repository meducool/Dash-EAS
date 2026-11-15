<?php

namespace App\Domain\Brands;

use App\Domain\SupplierPrices\SupplierProductMatcher;
use App\Models\ActivityLog;
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

    /**
     * Profiles written several ways under the same brand ("EcoContact 6" /
     * "ECOCONTACT6"): they give the same tyre two references, hence duplicates
     * in the POS and on the shop. Groups of 2+ profiles, every brand.
     *
     * @return array<int, array{brand_id: int, brand: string, profiles: array<int, array<string, mixed>>}>
     */
    public function duplicates(): array
    {
        $counts = Product::query()->whereNotNull('brand_id')->whereNotNull('profile')
            ->selectRaw('brand_id, TRIM(profile) as name, COUNT(*) as n')
            ->groupByRaw('brand_id, TRIM(profile)')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->brand_id.'|'.mb_strtolower($r->name) => (int) $r->n]);

        $groups = BrandProfile::query()->with('brand:id,name')->orderBy('name')->get()
            ->groupBy(fn (BrandProfile $p) => $p->brand_id.'|'.SupplierProductMatcher::profileKey($p->name))
            ->filter(fn ($group, $key) => $group->count() > 1 && ! str_ends_with($key, '|'));

        return $groups->map(fn ($group) => [
            'brand_id' => (int) $group->first()->brand_id,
            'brand' => (string) $group->first()->brand?->name,
            'profiles' => $group->map(fn (BrandProfile $p) => $this->present($p, $counts[$p->brand_id.'|'.mb_strtolower($p->name)] ?? 0))
                ->sortByDesc('products_count')->values()->all(),
        ])->sortBy('brand')->values()->all();
    }

    /**
     * Merges $source into $target (same brand): the source's products take the
     * target's name, then the source is removed. Product references are locked
     * and do not change; a missing code on the target takes the source's.
     *
     * @return array{id: int, brand_id: int, name: string, code: ?string, products_count: int}
     */
    public function merge(BrandProfile $source, BrandProfile $target, ?int $userId = null, ?string $userName = null): array
    {
        if ($source->id === $target->id || $source->brand_id !== $target->brand_id) {
            throw ValidationException::withMessages(['into_id' => ['Choisissez un autre profil de la même marque.']]);
        }

        $moved = DB::transaction(function () use ($source, $target) {
            $moved = Product::query()
                ->where('brand_id', $source->brand_id)
                ->whereRaw('TRIM(profile) = ?', [$source->name])
                ->update(['profile' => $target->name]);
            if (! $target->code && $source->code) {
                $target->update(['code' => $source->code]);
            }
            $source->delete();

            return $moved;
        });

        ActivityLog::create([
            'action' => ActivityLog::ACTION_UPDATE,
            'entity_type' => ActivityLog::ENTITY_PRODUCT,
            'entity_id' => $target->id,
            'entity_label' => 'Profil '.$target->name,
            'description' => "Profil « {$source->name} » fusionné dans « {$target->name} » : {$moved} article(s) renommé(s)",
            'properties' => ['from' => $source->name, 'to' => $target->name, 'brand_id' => $target->brand_id, 'products' => $moved],
            'user_id' => $userId,
            'user_name' => $userName,
        ]);

        return $this->present($target->fresh(), Product::query()->where('brand_id', $target->brand_id)->whereRaw('TRIM(profile) = ?', [$target->name])->count());
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
