<?php

namespace App\Http\Controllers;

use App\Domain\Stock\DepotService;
use App\Models\Depot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reference list of depots (Paramètres › Dépôts, and every "Dépôt" picker). */
class DepotController extends Controller
{
    public function __construct(private DepotService $depots) {}

    public function index(): JsonResponse
    {
        return response()->json($this->depots->list()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        return response()->json($this->depots->create($validated['name']), 201);
    }

    public function update(Request $request, Depot $depot): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        return response()->json($this->depots->rename($depot, $validated['name']));
    }

    public function destroy(Depot $depot): JsonResponse
    {
        $this->depots->delete($depot);

        return response()->json(null, 204);
    }
}
