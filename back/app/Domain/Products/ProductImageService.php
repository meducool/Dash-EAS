<?php

namespace App\Domain\Products;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Photos of a catalogue article: up to MAX_IMAGES per product, the first one
 * (lowest position) being the main photo. Files live on the public disk.
 */
class ProductImageService
{
    public const MAX_IMAGES = 6;

    /** Max size of one photo, in kilobytes (validation rule `max`). */
    public const MAX_KB = 5120;

    private const DISK = 'public';

    /** @return Collection<int, ProductImage> */
    public function list(Product $product): Collection
    {
        return $product->images()->get();
    }

    /**
     * Adds the photos after the existing ones.
     *
     * @param  array<int, UploadedFile>  $files
     * @return Collection<int, ProductImage>
     */
    public function add(Product $product, array $files, ?int $userId = null): Collection
    {
        $count = $product->images()->count();
        if ($count + count($files) > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => sprintf(
                    '%d photo(s) au maximum par article : %d déjà enregistrée(s), %d place(s) restante(s).',
                    self::MAX_IMAGES, $count, max(0, self::MAX_IMAGES - $count),
                ),
            ]);
        }

        $position = $count === 0 ? 0 : (int) $product->images()->max('position') + 1;
        $stored = [];
        try {
            foreach ($files as $file) {
                $path = $file->store('products/'.$product->id, self::DISK);
                $stored[] = $path;
                ProductImage::query()->create([
                    'product_id' => $product->id,
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'size' => $file->getSize(),
                    'position' => $position++,
                    'created_by' => $userId,
                ]);
            }
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($stored);
            throw $e;
        }

        return $this->list($product);
    }

    /** @return Collection<int, ProductImage> */
    public function delete(ProductImage $image): Collection
    {
        $product = $image->product;
        Storage::disk(self::DISK)->delete($image->path);
        $image->delete();

        return $this->renumber($product);
    }

    /**
     * New order of the photos (first = main photo). Every photo of the product must be listed.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, ProductImage>
     */
    public function reorder(Product $product, array $ids): Collection
    {
        $current = $product->images()->pluck('id')->all();
        $ids = array_values(array_map('intval', $ids));
        if (count($ids) !== count(array_unique($ids)) || array_diff($current, $ids) !== [] || array_diff($ids, $current) !== []) {
            throw ValidationException::withMessages(['ids' => 'La liste doit contenir chaque photo de l\'article une seule fois.']);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $position => $id) {
                ProductImage::query()->whereKey($id)->update(['position' => $position]);
            }
        });

        return $this->list($product);
    }

    /**
     * Runs the deletion of the product, then removes its photo files — kept
     * when the deletion fails (rows go with the FK cascade).
     */
    public function afterProductDeleted(Product $product, callable $delete): void
    {
        $paths = $product->images()->pluck('path')->all();
        $delete();
        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
        }
        Storage::disk(self::DISK)->deleteDirectory('products/'.$product->id);
    }

    /** @return Collection<int, ProductImage> */
    private function renumber(Product $product): Collection
    {
        $images = $this->list($product);
        foreach ($images as $position => $image) {
            if ($image->position !== $position) {
                $image->update(['position' => $position]);
            }
        }

        return $images;
    }
}
