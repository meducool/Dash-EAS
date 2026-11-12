<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// Accounts
Route::middleware('permission:view accounts')->group(function () {
    Route::get('accounts', [AccountController::class, 'index']);
    Route::get('accounts/{account}', [AccountController::class, 'show']);
});
Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:create accounts');
Route::put('accounts/{account}', [AccountController::class, 'update'])->middleware('permission:edit accounts');
Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->middleware('permission:delete accounts');
Route::post('accounts/transfer', [AccountController::class, 'transfer'])->middleware('permission:transfer accounts');

// Cash Flow / Transactions
Route::middleware('permission:view cash-flow')->group(function () {
    Route::get('/transactions-summary', [TransactionController::class, 'summary']);
    Route::get('/transactions-filters', [TransactionController::class, 'filters']);
    Route::get('transactions', [TransactionController::class, 'index']);
    Route::get('transactions/{transaction}', [TransactionController::class, 'show']);
});
Route::post('transactions', [TransactionController::class, 'store'])->middleware('permission:create cash-flow');
Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->middleware('permission:edit cash-flow');
Route::post('transactions/{transaction}/settle', [TransactionController::class, 'settle'])->middleware('permission:edit cash-flow');
Route::middleware('permission:transfer accounts')->group(function () {
    Route::post('transactions/{transaction}/deposit', [TransactionController::class, 'deposit']);
    Route::post('transactions/{transaction}/mark-deposited', [TransactionController::class, 'markDeposited']);
    Route::delete('transactions/{transaction}/deposit', [TransactionController::class, 'cancelDeposit']);
});
Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->middleware('permission:delete cash-flow');
