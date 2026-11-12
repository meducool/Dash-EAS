<?php

namespace App\Http\Controllers;

use App\Domain\Brands\BrandProfileService;
use App\Models\Brand;
use App\Models\BrandProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tyre profiles of a brand (e.g. MICHELIN → PRIMACY 4, PILOT SPORT 5). */
class BrandProfileController extends Controller
{
    public function __construct(private BrandProfileService $profiles) {}

    public function index(Brand $brand): JsonResponse
    {
        return response()->json($this->profiles->list($brand)->values());
    }

    public function store(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:15', 'regex:/^[A-Za-z0-9.+]+$/'],
        ]);

        return response()->json($this->profiles->create($brand, $validated['name'], $validated['code'] ?? null), 201);
    }

    public function update(Request $request, BrandProfile $profile): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:15', 'regex:/^[A-Za-z0-9.+]+$/'],
        ]);

        return response()->json($this->profiles->rename($profile, $validated['name'], $validated['code'] ?? null, $request->exists('code')));
    }

    public function destroy(BrandProfile $profile): JsonResponse
    {
        $this->profiles->delete($profile);

        return response()->json(null, 204);
    }
}
