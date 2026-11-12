import { Component, OnInit, computed, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AccountService } from '../data-access/account.service';
import { AuthService } from '../../../core/services/auth.service';
import { Account, AccountPayload, TransferPayload } from '../models/account.model';
import { AccountFormComponent } from '../account-form/account-form.component';
import { TransferFormComponent } from '../transfer-form/transfer-form.component';
import { CashFlowService } from '../../../core/services/cash-flow.service';
import { Transaction, TransactionSummary } from '../../../core/models/transaction.model';
import { AutoRefreshControlComponent } from '../../../shared/auto-refresh-control/auto-refresh-control.component';
import { PurchasePaymentDetailComponent } from '../../purchases/components/purchase-payment-detail/purchase-payment-detail.component';
import { SalePaymentDetailComponent } from '../../sales/components/sale-payment-detail/sale-payment-detail.component';

/** Today as `YYYY-MM-DD` in the browser's time zone (toISOString() would use UTC). */
function localToday(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

@Component({
  selector: 'app-accounts-page',
  standalone: true,
  imports: [CommonModule, FormsModule, AccountFormComponent, TransferFormComponent, AutoRefreshControlComponent, PurchasePaymentDetailComponent, SalePaymentDetailComponent],
  templateUrl: './accounts-page.component.html',
  styleUrls: ['./accounts-page.component.scss']
})
export class AccountsPageComponent implements OnInit {
  viewingPaymentId = signal<number | null>(null);
  viewingSalePaymentId = signal<number | null>(null);
  accounts = signal<Account[]>([]);
  loading = signal(false);

  showAccountForm = signal(false);
  showTransferForm = signal(false);
  editingAccount = signal<Account | null>(null);

  selectedAccount = signal<Account | null>(null);
  accountTransactions = signal<Transaction[]>([]);
  accountSummary = signal<TransactionSummary>({ income: 0, expenses: 0, balance: 0, pending_income: 0, pending_expense: 0 });
  loadingTransactions = signal(false);

  pendingTransactions = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return this.accountTransactions().filter(t =>
      (t.method === 'Chèque' || t.method === 'Effet') && t.date > today
    );
  });

  settledTransactions = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return this.accountTransactions().filter(t =>
      !((t.method === 'Chèque' || t.method === 'Effet') && t.date > today)
    );
  });

  // ── Versement des chèques en banque ──────────────────────────────────────
  // Each received cheque / effet of the account can be deposited in one click:
  // its own line moves to the bank account (no separate transfer line). It can
  // also be marked as already deposited when a transfer was typed by hand.
  depositCheque = signal<Transaction | null>(null);
  depositMode = signal<'deposit' | 'mark'>('deposit');
  depositAccountId = signal<number | null>(null);
  depositDate = signal('');
  depositSaving = signal(false);
  depositError = signal('');
  cancellingDepositId = signal<number | null>(null);

  /** Accounts a cheque can be deposited on: every other active account, banks first. */
  depositTargets = computed(() =>
    this.accounts()
      .filter((a) => a.is_active && a.id !== this.selectedAccount()?.id)
      .sort((a, b) => Number(b.type === 'bank') - Number(a.type === 'bank') || a.name.localeCompare(b.name)),
  );

  canDeposit(t: Transaction): boolean {
    return t.type === 'income'
      && (t.method === 'Chèque' || t.method === 'Effet')
      && !t.transfer_id
      && this.authService.hasPermission('transfer accounts');
  }

  openDeposit(t: Transaction, mode: 'deposit' | 'mark'): void {
    this.depositCheque.set(t);
    this.depositMode.set(mode);
    this.depositAccountId.set(this.depositTargets().find((a) => a.type === 'bank')?.id ?? this.depositTargets()[0]?.id ?? null);
    this.depositDate.set(localToday());
    this.depositError.set('');
  }

  closeDeposit(): void {
    if (this.depositSaving()) return;
    this.depositCheque.set(null);
  }

  confirmDeposit(): void {
    const cheque = this.depositCheque();
    const date = this.depositDate();
    if (!cheque || this.depositSaving()) return;
    if (!date) {
      this.depositError.set('Indiquez la date du versement.');
      return;
    }

    let request$;
    if (this.depositMode() === 'deposit') {
      const accountId = this.depositAccountId();
      if (!accountId) {
        this.depositError.set('Choisissez le compte bancaire.');
        return;
      }
      request$ = this.cashFlowService.depositCheque(cheque.id, accountId, date);
    } else {
      request$ = this.cashFlowService.markChequeDeposited(cheque.id, date);
    }

    this.depositSaving.set(true);
    this.depositError.set('');
    request$.subscribe({
      next: () => {
        this.depositSaving.set(false);
        this.depositCheque.set(null);
        this.refreshSelectedAccount();
      },
      error: (err) => {
        this.depositSaving.set(false);
        const errors = err?.error?.errors;
        const firstError = errors ? (Object.values(errors)[0] as string[] | undefined)?.[0] : null;
        this.depositError.set(firstError || err?.error?.message || "Le versement n'a pas pu être enregistré.");
      },
    });
  }

  cancelDeposit(t: Transaction): void {
    const from = this.accounts().find((a) => a.id === t.deposited_from_account_id)?.name;
    const message = t.deposited_from_account_id
      ? `Annuler ce versement ? Le chèque revient sur le compte « ${from ?? 'd\'origine'} ».`
      : 'Retirer la mention « versé » de ce chèque ?';
    if (!confirm(message)) return;

    this.cancellingDepositId.set(t.id);
    this.cashFlowService.cancelChequeDeposit(t.id).subscribe({
      next: () => {
        this.cancellingDepositId.set(null);
        this.refreshSelectedAccount();
      },
      error: (err) => {
        this.cancellingDepositId.set(null);
        alert(err?.error?.message || "L'annulation a échoué.");
      },
    });
  }

  /** Name of the account a deposited cheque came from (e.g. "Portefeuille"). */
  depositedFromName(t: Transaction): string | null {
    return this.accounts().find((a) => a.id === t.deposited_from_account_id)?.name ?? null;
  }

  private refreshSelectedAccount(): void {
    this.loadAccounts();
    const account = this.selectedAccount();
    if (account) this.loadAccountTransactions(account);
  }

  constructor(
    private accountService: AccountService,
    private cashFlowService: CashFlowService,
    public authService: AuthService
  ) {}

  ngOnInit() {
    this.loadAccounts();
  }

  loadAccounts() {
    this.loading.set(true);
    this.accountService.getAccounts({ all: '1' }).subscribe({
      next: (response) => {
        const accounts = Array.isArray(response) ? response : (response.data ?? []);
        this.accounts.set(accounts);
        this.loading.set(false);
        if (this.selectedAccount()) {
          const updated = accounts.find((a: Account) => a.id === this.selectedAccount()?.id);
          if (updated) {
            this.selectedAccount.set(updated);
          }
        }
      },
      error: () => this.loading.set(false)
    });
  }

  getIcon(type: string): string {
    switch (type) {
      case 'cash':
        return '💵';
      case 'bank':
        return '🏦';
      case 'person':
        return '👤';
      default:
        return '💼';
    }
  }

  getTypeLabel(type: string): string {
    switch (type) {
      case 'cash':
        return 'Caisse';
      case 'bank':
        return 'Banque';
      case 'person':
        return 'Personne';
      default:
        return type;
    }
  }

  openAddForm() {
    this.editingAccount.set(null);
    this.showAccountForm.set(true);
  }

  openEditForm(account: Account, event: Event) {
    event.stopPropagation();
    this.editingAccount.set(account);
    this.showAccountForm.set(true);
  }

  onAccountSubmit(payload: AccountPayload) {
    const edit = this.editingAccount();
    if (edit) {
      this.accountService.updateAccount(edit.id, payload).subscribe(() => {
        this.showAccountForm.set(false);
        this.loadAccounts();
      });
    } else {
      this.accountService.createAccount(payload).subscribe(() => {
        this.showAccountForm.set(false);
        this.loadAccounts();
      });
    }
  }

  deleteAccount(account: Account, event: Event) {
    event.stopPropagation();
    if (confirm(`Supprimer le compte ${account.name} ?`)) {
      this.accountService.deleteAccount(account.id).subscribe(() => {
        if (this.selectedAccount()?.id === account.id) {
          this.selectedAccount.set(null);
        }
        this.loadAccounts();
      });
    }
  }

  openTransferForm() {
    this.showTransferForm.set(true);
  }

  onTransferSubmit(payload: TransferPayload) {
    this.accountService.transfer(payload).subscribe(() => {
      this.showTransferForm.set(false);
      this.loadAccounts();
      if (this.selectedAccount()) {
        this.loadAccountTransactions(this.selectedAccount()!);
      }
    });
  }

  viewAccount(account: Account) {
    this.selectedAccount.set(account);
    this.loadAccountTransactions(account);
  }

  backToOverview() {
    this.selectedAccount.set(null);
  }

  openPaymentView(transaction: Transaction): void {
    if (transaction.purchase_payment_id) this.viewingPaymentId.set(transaction.purchase_payment_id);
  }

  closePaymentView(): void {
    this.viewingPaymentId.set(null);
  }

  openSalePaymentView(transaction: Transaction): void {
    if (transaction.sale_payment_id) this.viewingSalePaymentId.set(transaction.sale_payment_id);
  }

  closeSalePaymentView(): void {
    this.viewingSalePaymentId.set(null);
  }

  loadAccountTransactions(account: Account) {
    this.loadingTransactions.set(true);
    const filter = { account_id: account.id.toString(), per_page: '100' };

    this.cashFlowService.getTransactions(filter).subscribe({
      next: (res) => {
        this.accountTransactions.set(res.data);
        this.loadingTransactions.set(false);
      },
      error: () => this.loadingTransactions.set(false)
    });

    this.cashFlowService.getSummary(filter).subscribe(summary => {
      this.accountSummary.set(summary);
    });
  }
}
