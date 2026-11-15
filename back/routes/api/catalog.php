<?php

use App\Http\Controllers\BrandController;
use App\Http\Controllers\BrandProfileController;
use App\Http\Controllers\CarrierController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

// Cities (reference list — no specific permission required)
Route::get('cities', [CityController::class, 'index']);

// Brands
Route::middleware('permission:view brands')->group(function () {
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('brands/{brand}', [BrandController::class, 'show']);
});
Route::post('brands', [BrandController::class, 'store'])->middleware('permission:create brands');
Route::put('brands/{brand}', [BrandController::class, 'update'])->middleware('permission:edit brands');
Route::patch('brands/{brand}/toggle-active', [BrandController::class, 'toggleActive'])->middleware('permission:edit brands');
Route::delete('brands/{brand}', [BrandController::class, 'destroy'])->middleware('permission:delete brands');
// Tyre profiles of a brand
Route::get('brands/{brand}/profiles', [BrandProfileController::class, 'index'])->middleware('permission:view brands');
Route::post('brands/{brand}/profiles', [BrandProfileController::class, 'store'])->middleware('permission:edit brands');
Route::put('brand-profiles/{profile}', [BrandProfileController::class, 'update'])->middleware('permission:edit brands');
Route::delete('brand-profiles/{profile}', [BrandProfileController::class, 'destroy'])->middleware('permission:edit brands');
// Profiles written several ways ("EcoContact 6" / "ECOCONTACT6") and their merge.
Route::get('brand-profiles-duplicates', [BrandProfileController::class, 'duplicates'])->middleware('permission:view brands');
Route::post('brand-profiles/{profile}/merge', [BrandProfileController::class, 'merge'])->middleware('permission:edit brands');

// Products
Route::middleware('permission:view products')->group(function () {
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products-filters', [ProductController::class, 'filters']);
    Route::get('products/{product}', [ProductController::class, 'show']);
});
// Purchase history of a product (product sheet): purchase prices are purchase data.
Route::get('products/{product}/purchase-history', [ProductController::class, 'purchaseHistory'])->middleware('permission:view purchases');
// Purchase prices of a product (sale form « PU Achat »): whoever records or edits a sale.
Route::get('products/{product}/purchase-prices', [ProductController::class, 'purchasePrices'])->middleware('permission:create sales|edit sales');
// Photos of an article (product sheet, drag & drop): first = main photo.
Route::get('products/{product}/images', [ProductImageController::class, 'index'])->middleware('permission:view products');
Route::post('products/{product}/images', [ProductImageController::class, 'store'])->middleware('permission:edit products');
Route::put('products/{product}/images/order', [ProductImageController::class, 'reorder'])->middleware('permission:edit products');
Route::delete('product-images/{image}', [ProductImageController::class, 'destroy'])->middleware('permission:edit products');
Route::get('products-reference-preview', [ProductController::class, 'referencePreview'])->middleware('permission:create products');
Route::post('products', [ProductController::class, 'store'])->middleware('permission:create products');
Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:edit products');
Route::patch('products/{product}/toggle-active', [ProductController::class, 'toggleActive'])->middleware('permission:edit products');
Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:delete products');

// Suppliers
Route::get('suppliers-summary', [SupplierController::class, 'summary'])->middleware('permission:view suppliers');
Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:view suppliers');
Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:view suppliers');
Route::get('suppliers/{supplier}/profile', [SupplierController::class, 'profile'])->middleware('permission:view suppliers');
Route::get('suppliers/{supplier}/statement', [SupplierController::class, 'statement'])->middleware('permission:view suppliers');
Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:create suppliers');
Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:edit suppliers');
Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:delete suppliers');

// Supplier payments — settle several purchases with a single payment
Route::get('suppliers/{supplier}/unpaid-purchases', [SupplierPaymentController::class, 'unpaidPurchases'])->middleware('permission:view purchases');
Route::post('suppliers/{supplier}/payments', [SupplierPaymentController::class, 'store'])->middleware('permission:manage purchase-payments');
Route::delete('suppliers/{supplier}/payments/{payment}', [SupplierPaymentController::class, 'destroy'])->middleware('permission:manage purchase-payments');

// Carriers (Transporteurs)
Route::get('carriers', [CarrierController::class, 'index'])->middleware('permission:view carriers');
Route::get('carriers/{carrier}', [CarrierController::class, 'show'])->middleware('permission:view carriers');
Route::post('carriers', [CarrierController::class, 'store'])->middleware('permission:create carriers');
Route::put('carriers/{carrier}', [CarrierController::class, 'update'])->middleware('permission:edit carriers');
Route::delete('carriers/{carrier}', [CarrierController::class, 'destroy'])->middleware('permission:delete carriers');

// Partners (Partenaires)
Route::get('partners', [PartnerController::class, 'index'])->middleware('permission:view partners');
Route::get('partners/{partner}', [PartnerController::class, 'show'])->middleware('permission:view partners');
Route::post('partners', [PartnerController::class, 'store'])->middleware('permission:create partners');
Route::put('partners/{partner}', [PartnerController::class, 'update'])->middleware('permission:edit partners');
Route::delete('partners/{partner}', [PartnerController::class, 'destroy'])->middleware('permission:delete partners');

// Clients
Route::get('clients-summary', [ClientController::class, 'summary'])->middleware('permission:view clients');
Route::get('clients', [ClientController::class, 'index'])->middleware('permission:view clients');
Route::get('clients/export', [ClientController::class, 'export'])->middleware('permission:view clients');
Route::get('clients/{client}', [ClientController::class, 'show'])->middleware('permission:view clients');
Route::post('clients', [ClientController::class, 'store'])->middleware('permission:create clients');
Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('permission:edit clients');
Route::patch('clients/{client}', [ClientController::class, 'update'])->middleware('permission:edit clients');
Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('permission:delete clients');
