<?php

namespace App\Http\Controllers;

use App\Domain\Transactions\ChequeDepositService;
use App\Domain\Transactions\TransactionService;
use App\Http\Requests\Transactions\StoreTransactionRequest;
use App\Http\Requests\Transactions\UpdateTransactionRequest;
use App\Http\Resources\Transactions\TransactionResource;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(private TransactionService $transactionService) {}

    /**
     * Display a paginated list of transactions with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $paginated = $this->transactionService->list($request->all(), $this->canViewHr($request));

        if ($request->boolean('all')) {
            return response()->json(TransactionResource::collection($paginated)->resolve($request));
        }

        return response()->json([
            'current_page' => $paginated->currentPage(),
            'data' => TransactionResource::collection($paginated->items())->resolve($request),
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

    /**
     * Store a newly created transaction.
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->create($request->validated(), $request->user());

        return response()->json((new TransactionResource($transaction))->resolve($request), 201);
    }

    /**
     * Display the specified transaction.
     */
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->transactionService->guardConfidential($transaction, $this->canViewHr($request));

        return response()->json((new TransactionResource($transaction->load('account')))->resolve($request));
    }

    /**
     * Update the specified transaction.
     */
    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->transactionService->guardConfidential($transaction, $this->canViewHr($request));

        $transaction = $this->transactionService->update(
            $transaction,
            $request->validated(),
            $request->user()->id,
            $request->user()->name,
        );

        return response()->json((new TransactionResource($transaction))->resolve($request));
    }

    /**
     * Remove the specified transaction.
     */
    /**
     * Validate a pending Chèque/Effet before its due date (early cashing).
     */
    public function settle(Request $request, Transaction $transaction): JsonResponse
    {
        $this->transactionService->guardConfidential($transaction, $this->canViewHr($request));

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $transaction = $this->transactionService->settleEarly(
            $transaction,
            $validated['date'] ?? now()->toDateString(),
            $request->user()->id,
            $request->user()->name,
        );

        return response()->json((new TransactionResource($transaction))->resolve($request));
    }

    /**
     * Deposit a received cheque / effet at the bank: creates the transfer to
     * `destination_account_id` and marks the cheque as deposited.
     */
    public function deposit(Request $request, Transaction $transaction, ChequeDepositService $deposits): JsonResponse
    {
        $this->transactionService->guardConfidential($transaction, $this->canViewHr($request));

        $validated = $request->validate([
            'destination_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $cheque = $deposits->deposit(
            $transaction,
            Account::findOrFail($validated['destination_account_id']),
            $validated['date'],
            $request->user(),
        );

        return response()->json((new TransactionResource($cheque->load('account')))->resolve($request));
    }

    /** Mark a cheque deposited earlier through a transfer typed by hand (no new transfer). */
    public function markDeposited(Request $request, Transaction $transaction, ChequeDepositService $deposits): JsonResponse
    {
        $this->transactionService->guardConfidential($transaction, $this->canViewHr($request));

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $cheque = $deposits->markDeposited($transaction, $validated['date'] ?? now()->toDateString(), $request->user());

        return response()->json((new TransactionResource($cheque->load('account')))->resolve($request));
    }

    /** Undo a deposit (and delete the transfer it created, if any). */
    public function cancelDeposit(Request $request, Transaction $transaction, ChequeDepositService $deposits): JsonResponse
    {
        $this->transactionService->guardConfidential($transaction, $this->canViewHr($request));

        $cheque = $deposits->cancelDeposit($transaction, $request->user());

        return response()->json((new TransactionResource($cheque->load('account')))->resolve($request));
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $this->transactionService->guardConfidential($transaction, $this->canViewHr($request));

        $this->transactionService->delete($transaction, $request->user()->id, $request->user()->name);

        return response()->json(null, 204);
    }

    /**
     * Get summary totals (income, expenses, balance) with optional filters.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->transactionService->summary($request->all(), $this->canViewHr($request)));
    }

    /**
     * Get distinct values for filter dropdowns.
     */
    public function filters(Request $request): JsonResponse
    {
        return response()->json($this->transactionService->filters($this->canViewHr($request)));
    }

    /**
     * Whether the authenticated user is allowed to see confidential
     * (HR-charge) transactions.
     */
    private function canViewHr(Request $request): bool
    {
        return (bool) $request->user()?->can('view hr-charges');
    }
}
