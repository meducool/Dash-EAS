<?php

namespace App\Domain\Transactions;

use App\Models\Account;
use App\Models\ServicePayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ActivityLogService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deposit at the bank of a cheque / effet received on an account (typically
 * the "Portefeuille"), done on the cheque line itself: the line moves to the
 * bank account — no separate transfer line is created.
 */
class ChequeDepositService
{
    public const DEPOSITABLE_METHODS = ['Chèque', 'Effet'];

    public function __construct(private ActivityLogService $activityLog) {}

    /** Moves the cheque line to `$bank` and marks it as deposited on `$date`. */
    public function deposit(Transaction $cheque, Account $bank, string $date, User $user): Transaction
    {
        $this->guardDepositable($cheque);

        if ((int) $bank->id === (int) $cheque->account_id) {
            abort(422, 'Choisissez un compte différent du compte du chèque.');
        }

        $from = $cheque->account()->first();

        DB::transaction(function () use ($cheque, $bank, $date) {
            $cheque->update([
                'deposited_from_account_id' => $cheque->account_id,
                'account_id' => $bank->id,
                'deposited_at' => $date,
            ]);
            // A service payment also stores its account: keep both in step.
            ServicePayment::where('transaction_id', $cheque->id)->update(['account_id' => $bank->id]);
        });

        $fromName = $from?->name ?? '?';
        $this->log($cheque, "Transaction #{$cheque->id} versée de « {$fromName} » vers « {$bank->name} » le ".$this->fr($date), $user);

        return $cheque->fresh();
    }

    /** For a cheque already deposited through a transfer typed by hand: the line stays where it is. */
    public function markDeposited(Transaction $cheque, string $date, User $user): Transaction
    {
        $this->guardDepositable($cheque);

        $cheque->update(['deposited_at' => $date, 'deposited_from_account_id' => null]);

        $this->log($cheque, "Transaction #{$cheque->id} marquée comme déjà versée le ".$this->fr($date), $user);

        return $cheque->fresh();
    }

    /** Undoes a deposit: the line goes back to the account it came from, and the mark is cleared. */
    public function cancelDeposit(Transaction $cheque, User $user): Transaction
    {
        if ($cheque->deposited_at === null) {
            abort(422, "Ce chèque n'est pas marqué comme versé.");
        }

        DB::transaction(function () use ($cheque) {
            $changes = ['deposited_at' => null, 'deposited_from_account_id' => null];

            if ($cheque->deposited_from_account_id) {
                $changes['account_id'] = $cheque->deposited_from_account_id;
                ServicePayment::where('transaction_id', $cheque->id)->update(['account_id' => $cheque->deposited_from_account_id]);
            }

            $cheque->update($changes);
        });

        $this->log($cheque, "Versement de la transaction #{$cheque->id} annulé", $user);

        return $cheque->fresh();
    }

    private function guardDepositable(Transaction $cheque): void
    {
        $isCheque = $cheque->type === 'income'
            && in_array($cheque->method, self::DEPOSITABLE_METHODS, true)
            && $cheque->account_id !== null
            && $cheque->transfer_id === null;

        if (! $isCheque) {
            abort(422, 'Seul un chèque ou un effet reçu sur un compte peut être versé en banque.');
        }

        if ($cheque->deposited_at !== null) {
            abort(422, 'Ce chèque est déjà versé.');
        }
    }

    private function fr(string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    private function log(Transaction $cheque, string $description, User $user): void
    {
        $cheque->refresh()->load('account');
        $snapshot = $this->activityLog->buildTransactionSnapshot($cheque);
        $this->activityLog->logTransactionNote($cheque, $description, $snapshot, $user->id, $user->name);
    }
}
