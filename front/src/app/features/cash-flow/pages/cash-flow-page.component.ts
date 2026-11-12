import { CommonModule } from '@angular/common';
import { Component, OnInit, computed, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../../core/services/auth.service';
import { TransactionFormComponent } from '../components/transaction-form/transaction-form.component';
import { CashFlowService } from '../data-access/cash-flow.service';
import {
  PaginatedResponse,
  Transaction,
  TransactionFilters,
  TransactionPayload,
  TransactionSummary,
} from '../models/transaction.model';
import { AutoRefreshControlComponent } from '../../../shared/auto-refresh-control/auto-refresh-control.component';
import { AccountService } from '../../accounts/data-access/account.service';
import { Account } from '../../accounts/models/account.model';
import { PurchasePaymentDetailComponent } from '../../purchases/components/purchase-payment-detail/purchase-payment-detail.component';
import { SalePaymentDetailComponent } from '../../sales/components/sale-payment-detail/sale-payment-detail.component';
import { TransactionCategoryService } from '../../transaction-categories/data-access/transaction-category.service';
import { TransactionCategory } from '../../transaction-categories/models/transaction-category.model';
import { monthKey, monthKeyFromToday, monthOffset, monthTitle } from '../../../core/utils/due-period';

/** Colour family of a month: current, next, the one after, any later one — or already past. */
export type PendingTone = 'overdue' | 'current' | 'next' | 'after' | 'later';

export interface PendingMonth {
  key: string;
  title: string;
  /** "Mois courant", "Mois prochain", "Mois suivant", "En retard" — null for later months. */
  tag: string | null;
  tone: PendingTone;
  items: Transaction[];
  /** Signed: income adds, expense subtracts. */
  total: number;
}

const MONTH_TAGS: Record<PendingTone, string | null> = {
  overdue: 'En retard',
  current: 'Mois courant',
  next: 'Mois prochain',
  after: 'Mois suivant',
  later: null,
};

function toneOf(offset: number): PendingTone {
  if (offset < 0) return 'overdue';
  return (['current', 'next', 'after'] as const)[offset] ?? 'later';
}

/** Today as `YYYY-MM-DD` in the browser's time zone (toISOString() would use UTC). */
function localToday(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function signedAmount(t: Transaction): number {
  const amount = Number(t.amount) || 0;
  return t.type === 'expense' ? -amount : amount;
}

@Component({
  selector: 'app-cash-flow-page',
  standalone: true,
  imports: [CommonModule, FormsModule, TransactionFormComponent, AutoRefreshControlComponent, PurchasePaymentDetailComponent, SalePaymentDetailComponent],
  templateUrl: './cash-flow-page.component.html',
  styleUrls: ['./cash-flow-page.component.scss'],
})
export class CashFlowPageComponent implements OnInit {
  viewingPaymentId = signal<number | null>(null);
  viewingSalePaymentId = signal<number | null>(null);
  transactions = signal<Transaction[]>([]);
  summary = signal<TransactionSummary>({ income: 0, expenses: 0, balance: 0, pending_income: 0, pending_expense: 0 });
  filterOptions = signal<TransactionFilters>({ categories: [], persons: [], partners: [], accounts: [] });

  currentPage = signal(1);
  lastPage = signal(1);
  total = signal(0);
  perPage = signal(100);

  filterType = signal('');
  filterCategory = signal('');
  filterSubcategory = signal('');
  filterAccount = signal('');
  filterPerson = signal('');
  filterDateFrom = signal('');
  filterDateTo = signal('');
  filterSearch = signal('');
  filterPartner = signal('');
  filterAmountMin = signal('');
  filterAmountMax = signal('');
  sortBy = signal('');
  sortDirection = signal<'asc' | 'desc'>('asc');

  pendingTransactions = signal<Transaction[]>([]);
  loadingPending = signal(false);
  // Closed by default — the header opens it on click.
  pendingCollapsed = signal(true);
  /** Month groups the user folded (by `YYYY-MM` key). */
  collapsedMonths = signal<ReadonlySet<string>>(new Set());

  /** Upcoming transactions grouped by due month, latest month first, latest date first. */
  pendingMonths = computed<PendingMonth[]>(() => {
    const groups = new Map<string, Transaction[]>();
    for (const t of this.pendingTransactions()) {
      const key = monthKey(t.date) ?? '0000-00';
      groups.set(key, [...(groups.get(key) ?? []), t]);
    }

    return [...groups.entries()]
      .sort(([a], [b]) => b.localeCompare(a))
      .map(([key, items]) => {
        const tone = toneOf(monthOffset(key));
        return {
          key,
          title: monthTitle(key),
          tag: MONTH_TAGS[tone],
          tone,
          items: [...items].sort((a, b) => b.date.localeCompare(a.date)),
          total: items.reduce((sum, t) => sum + signedAmount(t), 0),
        };
      });
  });

  /** The three cards: current month, next month, the one after — shown even when empty. */
  pendingCards = computed<PendingMonth[]>(() =>
    [0, 1, 2].map((offset) => {
      const key = monthKeyFromToday(offset);
      const tone = toneOf(offset);
      const group = this.pendingMonths().find((m) => m.key === key);
      return group ?? { key, title: monthTitle(key), tag: MONTH_TAGS[tone], tone, items: [], total: 0 };
    }),
  );

  pendingTotal = computed(() => {
    const items = this.pendingTransactions();
    return { count: items.length, total: items.reduce((sum, t) => sum + signedAmount(t), 0) };
  });

  toggleMonth(key: string): void {
    this.collapsedMonths.update((set) => {
      const next = new Set(set);
      if (!next.delete(key)) next.add(key);
      return next;
    });
  }

  loading = signal(false);
  deletingTransactionId = signal<number | null>(null);
  showForm = signal(false);
  editingTransaction = signal<Transaction | null>(null);
  accounts = signal<Account[]>([]);

  incomeCategoryTree = signal<TransactionCategory[]>([]);
  expenseCategoryTree = signal<TransactionCategory[]>([]);

  filterCategoryOptions = computed<TransactionCategory[]>(() => {
    if (this.filterType() === 'income') return this.incomeCategoryTree();
    if (this.filterType() === 'expense') return this.expenseCategoryTree();
    return [...this.incomeCategoryTree(), ...this.expenseCategoryTree()];
  });

  filterSubcategoryOptions = computed<TransactionCategory[]>(() => {
    const category = this.filterCategoryOptions().find((c) => c.name === this.filterCategory());
    return category?.children || [];
  });

  constructor(
    private cashFlowService: CashFlowService,
    private accountService: AccountService,
    private transactionCategoryService: TransactionCategoryService,
    public authService: AuthService,
  ) {}

  ngOnInit(): void {
    this.loadAccounts();
    this.loadFilters();
    this.loadCategoryTrees();
    this.loadData();
  }

  loadCategoryTrees(): void {
    this.transactionCategoryService.getTree('income', true).subscribe({
      next: (categories) => this.incomeCategoryTree.set(categories),
      error: () => this.incomeCategoryTree.set([]),
    });
    this.transactionCategoryService.getTree('expense', true).subscribe({
      next: (categories) => this.expenseCategoryTree.set(categories),
      error: () => this.expenseCategoryTree.set([]),
    });
  }

  loadData(): void {
    this.loading.set(true);
    this.loadingPending.set(true);
    const filters = this.buildFilters();

    this.cashFlowService.getTransactions({ ...filters, status: 'settled' }).subscribe({
      next: (response) => {
        this.transactions.set((response as PaginatedResponse<Transaction>).data);
        this.currentPage.set(response.current_page);
        this.lastPage.set(response.last_page);
        this.total.set(response.total);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });

    this.cashFlowService.getTransactions({ ...filters, status: 'pending', page: '1', per_page: '500' }).subscribe({
      next: (response) => {
        this.pendingTransactions.set((response as PaginatedResponse<Transaction>).data);
        this.loadingPending.set(false);
      },
      error: () => this.loadingPending.set(false),
    });

    this.cashFlowService.getSummary(filters).subscribe({
      next: (summary) => this.summary.set(summary),
    });
  }

  loadAccounts(): void {
    this.accountService.getAccounts({ all: '1', is_active: '1' }).subscribe({
      next: (res: any) => this.accounts.set(Array.isArray(res) ? res : res.data),
    });
  }

  loadFilters(): void {
    this.cashFlowService.getFilters().subscribe({
      next: (filters) => this.filterOptions.set(filters),
    });
  }

  private buildFilters(): Record<string, string> {
    return {
      page: this.currentPage().toString(),
      per_page: this.perPage().toString(),
      type: this.filterType(),
      account_id: this.filterAccount(),
      category: this.filterCategory(),
      subcategory: this.filterSubcategory(),
      person: this.filterPerson(),
      date_from: this.filterDateFrom(),
      date_to: this.filterDateTo(),
      search: this.filterSearch(),
      partner_id: this.filterPartner(),
      amount_min: this.filterAmountMin(),
      amount_max: this.filterAmountMax(),
      sort_by: this.sortBy(),
      sort_direction: this.sortDirection(),
    };
  }

  applyFilters(): void {
    this.currentPage.set(1);
    this.loadData();
  }

  toggleSort(column: string): void {
    if (this.sortBy() === column) {
      this.sortDirection.set(this.sortDirection() === 'asc' ? 'desc' : 'asc');
    } else {
      this.sortBy.set(column);
      this.sortDirection.set('asc');
    }
    this.currentPage.set(1);
    this.loadData();
  }

  resetFilters(): void {
    this.filterType.set('');
    this.filterAccount.set('');
    this.filterCategory.set('');
    this.filterSubcategory.set('');
    this.filterPerson.set('');
    this.filterDateFrom.set('');
    this.filterDateTo.set('');
    this.filterSearch.set('');
    this.filterPartner.set('');
    this.filterAmountMin.set('');
    this.filterAmountMax.set('');
    this.sortBy.set('');
    this.sortDirection.set('asc');
    this.currentPage.set(1);
    this.loadData();
  }

  goToPage(page: number): void {
    if (page >= 1 && page <= this.lastPage()) {
      this.currentPage.set(page);
      this.loadData();
    }
  }

  openAddForm(): void {
    this.editingTransaction.set(null);
    this.showForm.set(true);
  }

  openEditForm(transaction: Transaction): void {
    this.editingTransaction.set(transaction);
    this.showForm.set(true);
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

  closeForm(): void {
    this.showForm.set(false);
    this.editingTransaction.set(null);
  }

  onFormSubmit(payload: TransactionPayload): void {
    const editing = this.editingTransaction();

    if (editing) {
      this.cashFlowService.updateTransaction(editing.id, payload).subscribe({
        next: () => {
          this.closeForm();
          this.loadData();
          this.loadFilters();
        },
        error: (err) => {
          const msg = err?.error?.message || 'Erreur lors de la modification.';
          alert(msg);
        },
      });
    } else {
      this.cashFlowService.createTransaction(payload).subscribe({
        next: () => {
          this.closeForm();
          this.loadData();
          this.loadFilters();
        },
      });
    }
  }

  // ── Validation avant échéance ────────────────────────────────────────────
  // A client or supplier may ask to cash a cheque / effet before its due date:
  // the operation is re-dated to the cashing date and becomes settled.
  settlingTransaction = signal<Transaction | null>(null);
  settleDate = signal('');
  settling = signal(false);
  settleError = signal('');
  readonly today = localToday();

  openSettle(transaction: Transaction): void {
    this.settlingTransaction.set(transaction);
    this.settleDate.set(this.today);
    this.settleError.set('');
  }

  closeSettle(): void {
    if (this.settling()) return;
    this.settlingTransaction.set(null);
  }

  confirmSettle(): void {
    const transaction = this.settlingTransaction();
    const date = this.settleDate();
    if (!transaction || this.settling()) return;
    if (!date || date > this.today) {
      this.settleError.set("La date d'encaissement doit être aujourd'hui ou une date passée.");
      return;
    }

    this.settling.set(true);
    this.settleError.set('');
    this.cashFlowService.settleTransaction(transaction.id, date).subscribe({
      next: () => {
        this.settling.set(false);
        this.settlingTransaction.set(null);
        this.loadData();
      },
      error: (err) => {
        this.settling.set(false);
        const errors = err?.error?.errors;
        const firstError = errors ? (Object.values(errors)[0] as string[] | undefined)?.[0] : null;
        this.settleError.set(firstError || err?.error?.message || 'La validation a échoué.');
      },
    });
  }

  deleteTransaction(transaction: Transaction): void {
    if (!confirm(`Supprimer cette transaction ?\n"${transaction.description}"`)) {
      return;
    }

    this.deletingTransactionId.set(transaction.id);

    this.cashFlowService.deleteTransaction(transaction.id).subscribe({
      next: () => {
        this.deletingTransactionId.set(null);
        this.loadData();
        this.loadFilters();
      },
      error: (err) => {
        this.deletingTransactionId.set(null);
        const msg = err?.error?.message || 'Erreur lors de la suppression.';
        alert(msg);
      },
    });
  }

  get pages(): number[] {
    const total = this.lastPage();
    const current = this.currentPage();
    const pages: number[] = [];
    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);

    for (let i = start; i <= end; i++) {
      pages.push(i);
    }

    return pages;
  }
}
