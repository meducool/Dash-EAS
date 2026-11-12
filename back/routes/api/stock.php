<?php

use App\Http\Controllers\DepotController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockMovementController;
use Illuminate\Support\Facades\Route;

// Stock
Route::middleware('permission:view stock')->group(function () {
    Route::get('stocks-summary', [StockController::class, 'summary']);
    Route::get('stocks-filters', [StockController::class, 'filters']);
    Route::get('stocks', [StockController::class, 'index']);
    Route::get('stocks/export', [StockController::class, 'export']);
    Route::get('stocks/{stock}', [StockController::class, 'show']);
});
Route::post('stocks', [StockController::class, 'store'])->middleware('permission:create stock');
Route::put('stocks/{stock}', [StockController::class, 'update'])->middleware('permission:edit stock');
Route::delete('stocks/{stock}', [StockController::class, 'destroy'])->middleware('permission:delete stock');
Route::post('stocks/import', [StockController::class, 'import'])->middleware('permission:import stock');

// Depots (reference list for the stock lots' "Dépôt")
Route::get('depots', [DepotController::class, 'index'])->middleware('permission:view stock');
Route::post('depots', [DepotController::class, 'store'])->middleware('permission:create stock');
Route::put('depots/{depot}', [DepotController::class, 'update'])->middleware('permission:edit stock');
Route::delete('depots/{depot}', [DepotController::class, 'destroy'])->middleware('permission:edit stock');

// Stock Movements (audit trail)
Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware('permission:view stock-movements');
