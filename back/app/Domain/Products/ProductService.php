<?php

namespace App\Domain\Products;

use App\Domain\SupplierPrices\SupplierProductMatcher;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Stock;
use App\Services\ActivityLogService;
use App\Services\StockMovementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function __construct(
        private StockMovementService $movements,
        private ProductReferenceService $references,
        private ActivityLogService $activityLog,
        private ProductImageService $images,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = $this->buildFilteredQuery($filters);

        if (! empty($filters['all'])) {
            return $query->get();
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(array $validated, ?int $userId): Product
    {
        $typed = trim((string) ($validated['reference'] ?? ''));
        if (($validated['type'] ?? null) !== 'tyre' && $typed !== '' && $this->referenceTaken($typed, 0)) {
            throw ValidationException::withMessages(['reference' => ['Cette référence est déjà utilisée par un autre produit.']]);
        }

        $product = DB::transaction(function () use ($validated, $userId) {
            $product = Product::create([
                'profile' => $validated['profile'] ?? null,
                'reference' => null, // generated below, once the id is known
                'type' => $validated['type'],
                'brand_id' => $validated['brand_id'] ?? null,
                'description' => $validated['description'] ?? null,
                'unit' => $validated['unit'] ?? 'piece',
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->upsertDetails($product, $validated);

            // Tyre: generated from its characteristics. Part / service: the
            // reference typed by the user (unique), generated when left empty.
            $typed = trim((string) ($validated['reference'] ?? ''));
            $reference = $product->type !== 'tyre' && $typed !== ''
                ? $typed
                : $this->references->generate($validated, $product->id, $product->id);
            $product->update(['reference' => $reference]);

            if ($product->type !== 'service') {
                // The first lot is created empty (quantity comes from purchases),
                // with the location / DOT / origin typed in the product form, if any.
                $lot = $validated['stock'] ?? [];
                $clean = fn (string $key) => ($value = trim((string) ($lot[$key] ?? ''))) !== '' ? $value : null;

                $stock = Stock::create([
                    'product_id' => $product->id,
                    'quantity' => 0,
                    'depot' => $clean('depot'),
                    'zone' => $clean('zone'),
                    'dot' => $clean('dot'),
                    'made_in' => $clean('made_in'),
                    'user_id' => $userId,
                ]);

                $this->movements->recordAutoCreate($stock, $userId);
            }

            return $product;
        });

        return $this->loadRelations($product);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    /**
     * The reference is locked once created: only an Administrator may correct
     * it (recorded in the activity log). An absent / empty reference keeps the
     * current one.
     */
    public function update(Product $product, array $validated, bool $isAdmin = false, ?int $userId = null, ?string $userName = null): Product
    {
        $current = $product->reference;
        $requested = array_key_exists('reference', $validated) && trim((string) $validated['reference']) !== ''
            ? ProductReferenceService::normalize($validated['reference'])
            : null;
        $changed = $requested !== null && $requested !== ProductReferenceService::normalize($current);

        if ($changed && ! $isAdmin) {
            throw ValidationException::withMessages([
                'reference' => ['La référence ne peut pas être modifiée. Seul un administrateur peut la corriger.'],
            ]);
        }
        if ($changed && $this->referenceTaken($requested, $product->id)) {
            throw ValidationException::withMessages(['reference' => ['Cette référence est déjà utilisée par un autre produit.']]);
        }

        DB::transaction(function () use ($product, $validated, $changed, $requested, $current) {
            $product->update([
                'profile' => $validated['profile'] ?? null,
                'reference' => $changed ? $requested : $current,
                'type' => $validated['type'],
                'brand_id' => $validated['brand_id'] ?? null,
                'description' => $validated['description'] ?? null,
                'unit' => $validated['unit'] ?? 'piece',
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->upsertDetails($product, $validated);
        });

        if ($changed) {
            $this->activityLog->logProductReferenceChanged($product, (string) $current, $requested, $userId, $userName);
        }

        return $this->loadRelations($product);
    }

    /** Reference the form shows before saving (the server re-computes it on save). */
    public function previewReference(array $data): string
    {
        return $this->references->generate($data);
    }

    /**
     * Tyres already in the catalogue with the same characteristics — brand,
     * dimensions, load / speed indexes, runflat and profile (compared without
     * case, spaces or punctuation: "EcoContact 6" = "ECOCONTACT6"). Shown by
     * the product form before a second copy (and a "-2" reference) is created.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: int, reference: string, profile: ?string, is_active: bool, stock: int}>
     */
    public function similarTyres(array $data, ?int $exceptId = null): array
    {
        if (($data['type'] ?? 'tyre') !== 'tyre' || empty($data['tire_width']) || empty($data['tire_diameter']) || empty($data['brand_id'])) {
            return [];
        }
        $num = fn ($v) => $v === null || $v === '' ? null : round((float) $v, 2);
        $profileKey = SupplierProductMatcher::profileKey($data['profile'] ?? null);

        $candidates = Product::query()
            ->join('product_tyres', 'product_tyres.product_id', '=', 'products.id')
            ->where('products.type', 'tyre')
            ->where('products.brand_id', (int) $data['brand_id'])
            ->where('product_tyres.tire_width', $num($data['tire_width']))
            ->where('product_tyres.tire_diameter', $num($data['tire_diameter']))
            ->when($num($data['tire_height'] ?? null) !== null,
                fn ($q) => $q->where('product_tyres.tire_height', $num($data['tire_height'])),
                fn ($q) => $q->whereNull('product_tyres.tire_height'))
            ->when($exceptId, fn ($q) => $q->where('products.id', '!=', $exceptId))
            ->get(['products.id', 'products.reference', 'products.profile', 'products.is_active',
                'product_tyres.tire_load_index', 'product_tyres.tire_speed_index', 'product_tyres.tire_runflat']);

        $same = fn ($a, $b) => mb_strtoupper(trim((string) $a)) === mb_strtoupper(trim((string) $b));
        $found = $candidates->filter(fn ($p) => $same($p->tire_load_index, $data['tire_load_index'] ?? null)
            && $same($p->tire_speed_index, $data['tire_speed_index'] ?? null)
            && (bool) $p->tire_runflat === (bool) ($data['tire_runflat'] ?? false)
            && SupplierProductMatcher::profileKey($p->profile) === $profileKey);
        if ($found->isEmpty()) {
            return [];
        }
        $stock = Stock::query()->whereIn('product_id', $found->pluck('id'))->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) as qty')->pluck('qty', 'product_id');

        return $found->take(5)->map(fn ($p) => [
            'id' => (int) $p->id,
            'reference' => (string) $p->reference,
            'profile' => $p->profile,
            'is_active' => (bool) $p->is_active,
            'stock' => (int) ($stock[$p->id] ?? 0),
        ])->values()->all();
    }

    private function referenceTaken(string $reference, int $exceptId): bool
    {
        return Product::query()
            ->whereRaw('LOWER(TRIM(reference)) = ?', [mb_strtolower($reference)])
            ->where('id', '!=', $exceptId)
            ->exists();
    }

    public function delete(Product $product): void
    {
        // Files removed only once the article is really gone (rows follow the FK cascade).
        $this->images->afterProductDeleted($product, fn () => $product->delete());
    }

    public function toggleActive(Product $product): Product
    {
        $product->update(['is_active' => ! $product->is_active]);

        return $this->loadRelations($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'brands' => Brand::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'types' => ['tyre', 'part', 'service'],
            'seasons' => ['summer', 'winter', 'all_season'],
            'part_categories' => ['brakes', 'lubricants', 'engine', 'suspension', 'filters', 'electrical', 'body', 'other'],
            'service_categories' => ['mechanical', 'oil', 'tires', 'bodywork', 'diagnostic', 'other'],
            'units' => Product::distinct()->whereNotNull('unit')->where('unit', '!=', '')->pluck('unit')->sort()->values(),
            'profiles' => Product::distinct()->whereNotNull('profile')->where('profile', '!=', '')->orderBy('profile')->pluck('profile')->values(),
        ];
    }

    public function loadRelations(Product $product): Product
    {
        return $product->load(['brand', 'tyre', 'part', 'service']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildFilteredQuery(array $filters): Builder
    {
        $query = Product::with(['brand', 'tyre', 'part', 'service']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('profile', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('brand', function ($bq) use ($search) {
                        $bq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('part', function ($pq) use ($search) {
                        $pq->where('oem_reference', 'like', "%{$search}%")
                            ->orWhere('compatibility', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        if (! empty($filters['profile'])) {
            $query->where('profile', $filters['profile']);
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $filters['is_active']);
        }

        if (! empty($filters['tire_season'])) {
            $query->whereHas('tyre', function ($q) use ($filters) {
                $q->where('tire_season', $filters['tire_season']);
            });
        }

        if (! empty($filters['part_category'])) {
            $query->whereHas('part', function ($q) use ($filters) {
                $q->where('category', $filters['part_category']);
            });
        }

        if (! empty($filters['service_category'])) {
            $query->whereHas('service', function ($q) use ($filters) {
                $q->where('category', $filters['service_category']);
            });
        }

        $sortable = ['reference', 'type', 'created_at'];
        if (! empty($filters['sort_by']) && in_array($filters['sort_by'], $sortable, true)) {
            $direction = ($filters['sort_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $query->orderBy($filters['sort_by'], $direction);
        } else {
            $query->orderBy('id', 'desc');
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function upsertDetails(Product $product, array $validated): void
    {
        switch ($product->type) {
            case 'tyre':
                $product->tyre()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'tire_width' => $validated['tire_width'] ?? null,
                        'tire_height' => $validated['tire_height'] ?? null,
                        'tire_diameter' => $validated['tire_diameter'] ?? null,
                        'tire_load_index' => $validated['tire_load_index'] ?? null,
                        'tire_speed_index' => $validated['tire_speed_index'] ?? null,
                        'tire_season' => $validated['tire_season'] ?? null,
                        'tire_runflat' => $validated['tire_runflat'] ?? false,
                        'tire_reinforced' => $validated['tire_reinforced'] ?? false,
                        'tire_marking' => $validated['tire_marking'] ?? null,
                        'eu_fuel' => $validated['eu_fuel'] ?? null,
                        'eu_wet_grip' => $validated['eu_wet_grip'] ?? null,
                        'eu_noise_db' => $validated['eu_noise_db'] ?? null,
                        'eu_noise_class' => $validated['eu_noise_class'] ?? null,
                    ],
                );
                $product->part()->delete();
                $product->service()->delete();
                break;

            case 'part':
                $product->part()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'category' => $validated['part_category'] ?? 'other',
                        'oem_reference' => $validated['oem_reference'] ?? null,
                        'compatibility' => $validated['compatibility'] ?? null,
                    ],
                );
                $product->tyre()->delete();
                $product->service()->delete();
                break;

            case 'service':
                $product->service()->updateOrCreate(
                    ['product_id' => $product->id],
                    [
                        'category' => $validated['service_category'] ?? 'other',
                        'duration_minutes' => $validated['duration_minutes'] ?? null,
                        'selling_price' => $validated['selling_price'] ?? null,
                    ],
                );
                $product->tyre()->delete();
                $product->part()->delete();
                break;
        }
    }
}
