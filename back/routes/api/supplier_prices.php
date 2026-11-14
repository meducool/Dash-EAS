<?php

use App\Http\Controllers\SupplierPriceController;
use Illuminate\Support\Facades\Route;

// Tarifs fournisseurs — search / comparison / brands (view), price lists (manage).
Route::get('supplier-prices', [SupplierPriceController::class, 'index'])->middleware('permission:view supplier-prices');
Route::get('supplier-prices-settings', [SupplierPriceController::class, 'settings'])->middleware('permission:view supplier-prices');
// Default margin: Administrator only (checked in the controller).
Route::put('supplier-prices-settings', [SupplierPriceController::class, 'updateSettings'])->middleware('permission:manage supplier-prices');
Route::get('supplier-prices-filters', [SupplierPriceController::class, 'filters'])->middleware('permission:view supplier-prices');
Route::get('supplier-prices-brands', [SupplierPriceController::class, 'brands'])->middleware('permission:view supplier-prices');
Route::post('supplier-prices/{item}/product', [SupplierPriceController::class, 'createProduct'])
    ->middleware(['permission:view supplier-prices', 'permission:create products']);

Route::get('supplier-price-files', [SupplierPriceController::class, 'files'])->middleware('permission:view supplier-prices');
// Declared before supplier-price-files/{file} so "template" is not read as an id.
Route::get('supplier-price-files/template', [SupplierPriceController::class, 'template'])->middleware('permission:view supplier-prices');
Route::get('supplier-price-files/{file}', [SupplierPriceController::class, 'showFile'])->middleware('permission:view supplier-prices');
Route::post('supplier-price-files', [SupplierPriceController::class, 'storeFile'])->middleware('permission:manage supplier-prices');
// POST (not PUT): a replacement file is sent as multipart/form-data.
Route::post('supplier-price-files/{file}', [SupplierPriceController::class, 'updateFile'])->middleware('permission:manage supplier-prices');
Route::post('supplier-price-files/{file}/refresh', [SupplierPriceController::class, 'refreshFile'])->middleware('permission:manage supplier-prices');
Route::delete('supplier-price-files/{file}', [SupplierPriceController::class, 'destroyFile'])->middleware('permission:manage supplier-prices');
