<?php

namespace App\Http\Controllers;

use App\Domain\Products\ProductService;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\Products\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(private ProductService $productService) {}

    public function index(Request $request): JsonResponse
    {
        $paginated = $this->productService->list($request->all());

        if ($request->boolean('all')) {
            return response()->json(ProductResource::collection($paginated)->resolve($request));
        }

        return response()->json([
            'current_page' => $paginated->currentPage(),
            'data' => ProductResource::collection($paginated->items())->resolve($request),
            'first_page_url' => $paginated->url(1),
            'from' => $paginated->firstItem(),
            'last_page' => $paginated->lastPage(),
            'last_page_url' => $paginated->url($paginated->lastPage()),
            'links' => $paginated->linkCollection()->toArray(),
            'next_page_url' => $paginated->nextPageUrl(),
            'path' => $paginated->path(),
            'per_page' => $paginated->perPage(),
            'prev_page_url' => $paginated->previousPageUrl(),
            'to' => $paginated->lastItem(),
            'total' => $paginated->total(),
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->validated(),
            $request->user()?->id,
        );

        return response()->json((new ProductResource($product))->resolve($request), 201);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json((new ProductResource($this->productService->loadRelations($product)))->resolve(request()));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $user = $request->user();
        $product = $this->productService->update(
            $product,
            $request->validated(),
            (bool) $user?->hasRole('Administrator'),
            $user?->id,
            $user?->name,
        );

        return response()->json((new ProductResource($product))->resolve($request));
    }

    /** GET /api/products-reference-preview — the reference a new product would get. */
    public function referencePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:tyre,part,service',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'profile' => 'nullable|string|max:255',
            'tire_width' => 'nullable|numeric|min:0',
            'tire_height' => 'nullable|numeric|min:0',
            'tire_diameter' => 'nullable|numeric|min:0',
            'tire_load_index' => 'nullable|string|max:20',
            'tire_speed_index' => 'nullable|string|max:10',
            'tire_runflat' => 'nullable|boolean',
        ]);

        return response()->json(['reference' => $this->productService->previewReference($data)]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return response()->json(null, 204);
    }

    public function toggleActive(Product $product): JsonResponse
    {
        return response()->json((new ProductResource($this->productService->toggleActive($product)))->resolve(request()));
    }

    public function filters(): JsonResponse
    {
        return response()->json($this->productService->filters());
    }
}
