<?php

namespace App\Http\Controllers;

use App\Domain\Products\ProductImageService;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Photos of a catalogue article (product sheet). */
class ProductImageController extends Controller
{
    public function __construct(private ProductImageService $images) {}

    public function index(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->images->list($product), 'max' => ProductImageService::MAX_IMAGES]);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        // No SVG: it can carry scripts. The `image` rule checks the real content, not only the extension.
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.ProductImageService::MAX_IMAGES],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ProductImageService::MAX_KB],
        ], [
            'images.*.image' => 'Le fichier doit être une image.',
            'images.*.mimes' => 'Formats acceptés : JPG, PNG, WebP.',
            'images.*.max' => 'Une photo ne doit pas dépasser 5 Mo.',
        ]);

        $images = $this->images->add($product, $request->file('images'), $request->user()?->id);

        return response()->json(['data' => $images, 'max' => ProductImageService::MAX_IMAGES], 201);
    }

    public function reorder(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        return response()->json(['data' => $this->images->reorder($product, $validated['ids']), 'max' => ProductImageService::MAX_IMAGES]);
    }

    public function destroy(ProductImage $image): JsonResponse
    {
        return response()->json(['data' => $this->images->delete($image), 'max' => ProductImageService::MAX_IMAGES]);
    }
}
