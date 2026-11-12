<?php

namespace App\Domain\Stock;

use App\Models\Depot;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepotService
{
    /**
     * @return Collection<int, array{id: int, name: string, stocks_count: int}>
     */
    public function list(): Collection
    {
        $counts = Stock::query()
            ->whereNotNull('depot')
            ->selectRaw('TRIM(depot) as name, COUNT(*) as n')
            ->groupByRaw('TRIM(depot)')
            ->pluck('n', 'name')
            ->mapWithKeys(fn ($n, $name) => [mb_strtolower($name) => (int) $n]);

        return Depot::query()->orderBy('name')->get()
            ->map(fn (Depot $depot) => $this->present($depot, $counts[mb_strtolower($depot->name)] ?? 0));
    }

    public function create(string $name): array
    {
        $name = $this->clean($name);
        $this->guardUnique($name);

        return $this->present(Depot::create(['name' => $name]), 0);
    }

    /** Renames the depot and every stock lot stored in it. */
    public function rename(Depot $depot, string $name): array
    {
        $name = $this->clean($name);
        $this->guardUnique($name, $depot->id);

        $lots = DB::transaction(function () use ($depot, $name) {
            $lots = Stock::query()->whereRaw('TRIM(depot) = ?', [$depot->name])->update(['depot' => $name]);
            $depot->update(['name' => $name]);

            return $lots;
        });

        return $this->present($depot->fresh(), $lots);
    }

    /** A depot still holding stock lots cannot be removed (rename it instead). */
    public function delete(Depot $depot): void
    {
        $lots = Stock::query()->whereRaw('TRIM(depot) = ?', [$depot->name])->count();
        if ($lots > 0) {
            throw ValidationException::withMessages([
                'depot' => ["Ce dépôt est utilisé par {$lots} lot(s) de stock : renommez-le ou déplacez ces lots avant de le supprimer."],
            ]);
        }

        $depot->delete();
    }

    private function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    private function guardUnique(string $name, ?int $exceptId = null): void
    {
        $exists = Depot::query()
            ->where('name', $name)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => ['Ce dépôt existe déjà.']]);
        }
    }

    /** @return array{id: int, name: string, stocks_count: int} */
    private function present(Depot $depot, int $stocksCount): array
    {
        return ['id' => $depot->id, 'name' => $depot->name, 'stocks_count' => $stocksCount];
    }
}
