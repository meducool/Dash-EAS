<?php

use App\Http\Controllers\WebstoreController;
use Illuminate\Support\Facades\Route;

// Boutique en ligne (WooCommerce) — Administrator only by default.
Route::get('webstore/report', [WebstoreController::class, 'report'])->middleware('permission:view webstore');
Route::middleware('permission:manage webstore')->group(function () {
    Route::get('webstore/settings', [WebstoreController::class, 'settings']);
    Route::put('webstore/settings', [WebstoreController::class, 'updateSettings']);
    Route::post('webstore/test', [WebstoreController::class, 'test'])->middleware('throttle:10,1');
    Route::post('webstore/pull', [WebstoreController::class, 'pull'])->middleware('throttle:5,1');
});

// Step 2 — proposals: computed by who manages the shop, decided by the approver only.
Route::get('webstore/changes', [WebstoreController::class, 'changes'])->middleware('permission:view webstore');
Route::post('webstore/batches', [WebstoreController::class, 'generate'])->middleware(['permission:manage webstore', 'throttle:5,1']);
Route::post('webstore/changes/decide', [WebstoreController::class, 'decide'])->middleware('permission:approve webstore');
Route::put('webstore/changes/{change}/price', [WebstoreController::class, 'price'])->middleware('permission:approve webstore');

// Step 3 — publishing of the approved lines (writes to the site): approver only.
Route::post('webstore/publish', [WebstoreController::class, 'publish'])->middleware(['permission:approve webstore', 'throttle:3,1']);

// Step 4 — morning preparation (never publishes), status banner, history of the batches.
Route::get('webstore/status', [WebstoreController::class, 'status'])->middleware('permission:view webstore');
Route::get('webstore/batches', [WebstoreController::class, 'batches'])->middleware('permission:view webstore');
Route::post('webstore/prepare', [WebstoreController::class, 'prepareNow'])->middleware(['permission:manage webstore', 'throttle:3,1']);

// Anti-duplication: associate / dissociate a site product and a POS article by hand.
Route::post('webstore/links', [WebstoreController::class, 'link'])->middleware('permission:manage webstore');
Route::delete('webstore/links/{wooId}', [WebstoreController::class, 'unlink'])->whereNumber('wooId')->middleware('permission:manage webstore');
