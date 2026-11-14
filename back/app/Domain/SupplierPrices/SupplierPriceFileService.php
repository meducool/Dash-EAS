<?php

namespace App\Domain\SupplierPrices;

use App\Models\SupplierPriceFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Price lists of the Tarifs fournisseurs section: uploaded files or online
 * links (re-read on demand or every night), stored on the private disk so a
 * column-mapping change can re-read them.
 */
class SupplierPriceFileService
{
    private const DIRECTORY = 'supplier-prices';

    public function __construct(
        private SupplierPriceImporter $importer,
        private RemoteSpreadsheetFetcher $fetcher,
    ) {}

    public function list(): Collection
    {
        return SupplierPriceFile::query()
            ->with('supplier:id,name')
            ->orderBy('supplier_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data  validated: supplier_id, name, source_type, source_url, auto_refresh, column_mapping
     */
    public function create(array $data, ?UploadedFile $upload, ?int $userId): SupplierPriceFile
    {
        $file = SupplierPriceFile::query()->create([
            'supplier_id' => $data['supplier_id'],
            'name' => trim($data['name']),
            'source_type' => $data['source_type'],
            'source_url' => $data['source_type'] === 'url' ? trim($data['source_url']) : null,
            'auto_refresh' => $data['source_type'] === 'url' && ! empty($data['auto_refresh']),
            'margin_percent' => isset($data['margin_percent']) && $data['margin_percent'] !== '' ? round((float) $data['margin_percent'], 2) : null,
            'created_by' => $userId,
        ]);

        try {
            $this->storeSource($file, $upload);

            return $this->importer->import($file, $data['column_mapping'] ?? null);
        } catch (Throwable $e) {
            // Nothing usable was read: do not keep an empty price list behind.
            $this->deleteStored($file);
            $file->delete();
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierPriceFile $file, array $data, ?UploadedFile $upload): SupplierPriceFile
    {
        $sourceChanged = $upload !== null
            || (isset($data['source_url']) && $file->source_type === 'url' && trim($data['source_url']) !== $file->source_url);

        $file->fill(array_filter([
            'supplier_id' => $data['supplier_id'] ?? null,
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'source_url' => $file->source_type === 'url' && isset($data['source_url']) ? trim($data['source_url']) : null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('auto_refresh', $data)) {
            $file->auto_refresh = $file->source_type === 'url' && (bool) $data['auto_refresh'];
        }
        if (array_key_exists('is_active', $data)) {
            $file->is_active = (bool) $data['is_active'];
        }
        // Margin only changes the computed selling price: no re-import needed. Empty = default margin.
        if (array_key_exists('margin_percent', $data)) {
            $file->margin_percent = $data['margin_percent'] === null || $data['margin_percent'] === '' ? null : round((float) $data['margin_percent'], 2);
        }
        $supplierChanged = $file->isDirty('supplier_id');
        $file->save();

        if ($sourceChanged) {
            $this->storeSource($file, $upload);
        }
        if ($sourceChanged || $supplierChanged || array_key_exists('column_mapping', $data)) {
            return $this->importer->import($file, $data['column_mapping'] ?? null);
        }

        return $file->fresh(['supplier:id,name']);
    }

    /** Re-reads the price list: downloads the link again, or re-reads the uploaded file. */
    public function refresh(SupplierPriceFile $file): SupplierPriceFile
    {
        try {
            if ($file->source_type === 'url') {
                $this->storeSource($file, null);
            }

            return $this->importer->import($file);
        } catch (ValidationException $e) {
            $file->update(['last_error' => collect($e->errors())->flatten()->first()]);
            throw $e;
        }
    }

    public function delete(SupplierPriceFile $file): void
    {
        $this->deleteStored($file);
        $file->delete();
    }

    private function storeSource(SupplierPriceFile $file, ?UploadedFile $upload): void
    {
        if ($upload) {
            $extension = strtolower($upload->getClientOriginalExtension() ?: 'xlsx');
            $contents = (string) file_get_contents($upload->getRealPath());
            $original = mb_substr($upload->getClientOriginalName(), 0, 255);
        } elseif ($file->source_type === 'url' && $file->source_url) {
            ['contents' => $contents, 'extension' => $extension] = $this->fetcher->fetch($file->source_url);
            $original = mb_substr(basename((string) parse_url($file->source_url, PHP_URL_PATH)) ?: 'tarif', 0, 255);
        } else {
            throw ValidationException::withMessages(['file' => ['Choisissez un fichier Excel à téléverser.']]);
        }

        $path = self::DIRECTORY.'/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $contents);
        $previous = $file->stored_path;
        $file->update(['stored_path' => $path, 'original_filename' => $original]);
        if ($previous && $previous !== $path) {
            Storage::disk('local')->delete($previous);
        }
    }

    private function deleteStored(SupplierPriceFile $file): void
    {
        if ($file->stored_path) {
            Storage::disk('local')->delete($file->stored_path);
        }
    }
}
