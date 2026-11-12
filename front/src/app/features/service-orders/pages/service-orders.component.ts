import { Component, OnInit, signal, effect, inject, untracked, DestroyRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ServiceOrder, ServiceOrderFilters, ServiceOrderPayload, ServiceOrderSummary } from '../../../core/models/service-order.model';
import { PaymentStatus, ServiceOrderStatus, SERVICE_ORDER_STATUSES, SERVICE_ORDER_STATUS_LABELS, PAYMENT_STATUSES, PAYMENT_STATUS_LABELS } from '../../../core/constants/status.constants';
import { ServiceOrderService } from '../data-access/service-order.service';
import { ServiceOrderFormComponent } from '../service-order-form/service-order-form.component';
import { ServiceOrderDetailComponent } from '../service-order-detail/service-order-detail.component';
import { ServicePaymentPanelComponent } from '../service-payment-panel/service-payment-panel.component';
import { AuthService } from '../../../core/services/auth.service';
import { PAYMENT_METHODS } from '../../../core/constants/payment-method.constants';
import { AutoRefreshControlComponent } from '../../../shared/auto-refresh-control/auto-refresh-control.component';
import { DetailNavigator } from '../../../core/utils/detail-navigator';

@Component({
  selector: 'app-service-orders',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    RouterLink,
    AutoRefreshControlComponent,
    ServiceOrderFormComponent,
    ServiceOrderDetailComponent,
    ServicePaymentPanelComponent,
  ],
  templateUrl: './service-orders.component.html',
  styleUrls: ['./service-orders.component.scss'],
})
export class ServiceOrdersComponent implements OnInit {
  readonly SERVICE_ORDER_STATUSES = SERVICE_ORDER_STATUSES;
  readonly SERVICE_ORDER_STATUS_LABELS = SERVICE_ORDER_STATUS_LABELS;
  readonly PAYMENT_STATUSES = PAYMENT_STATUSES;
  readonly PAYMENT_STATUS_LABELS = PAYMENT_STATUS_LABELS;
  readonly PAYMENT_METHODS = PAYMENT_METHODS;

  serviceOrders = signal<ServiceOrder[]>([]);
  summary = signal<ServiceOrderSummary>({ total_revenue: 0, total_purchase: 0, total_margin: 0, total_paid: 0, remaining: 0 });
  filterOptions = signal<ServiceOrderFilters>({ service_products: [], commercials: [], clients: [], accounts: [] });

  currentPage = signal(1);
  lastPage = signal(1);
  total = signal(0);
  perPage = signal(20);

  filterSearch = signal('');
  filterClientId = signal<number | null>(null);
  filterProductId = signal<number | null>(null);
  filterStatus = signal('');
  filterPaymentStatus = signal('');
  filterPaymentMethod = signal('');
  filterCommercial = signal('');
  filterDateFrom = signal('');
  filterDateTo = signal('');
  sortBy = signal('');
  sortDirection = signal<'asc' | 'desc'>('asc');

  loading = signal(false);
  isExporting = signal(false);
  exportError = signal('');
  showForm = signal(false);
  loadingEdit = signal(false);
  loadingDetail = signal(false);
  editingOrder = signal<ServiceOrder | null>(null);
  detailOrder = signal<ServiceOrder | null>(null);
  paymentOrder = signal<ServiceOrder | null>(null);
  deletingId = signal<number | null>(null);

  /** Précédent / Suivant inside the detail modal — follows the table order across pages. */
  readonly detailNav = new DetailNavigator<ServiceOrder>({
    items: this.serviceOrders,
    current: this.detailOrder,
    page: this.currentPage,
    lastPage: this.lastPage,
    perPage: this.perPage,
    total: this.total,
    loading: this.loading,
    goToPage: (page) => this.goToPage(page),
  });

  /** Id whose full record has been requested — list rows are shown at once, then completed. */
  private requestedDetailId: number | null = null;

  private route = inject(ActivatedRoute);
  private destroyRef = inject(DestroyRef);

  constructor(
    private serviceOrderService: ServiceOrderService,
    public authService: AuthService,
  ) {
    // Whatever opens a record in the modal (👁️ button, Précédent / Suivant, a
    // page change at the edge of the list), fetch its full version once.
    effect(() => {
      const id = this.detailOrder()?.id ?? null;
      if (id === this.requestedDetailId) return;
      this.requestedDetailId = id;
      if (id !== null) untracked(() => this.loadFullDetail(id));
    });
  }

  ngOnInit(): void {
    this.loadFilters();
    this.loadData();
    this.loadSummary();

    this.route.queryParamMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(params => {
        const id = Number(params.get('id'));
        if (!Number.isFinite(id) || id <= 0) return;

        this.serviceOrderService.getServiceOrder(id).subscribe({
          next: order => {
            this.requestedDetailId = order.id; // already the full record
            this.detailOrder.set(order);
          },
        });
      });
  }

  loadData(): void {
    this.loading.set(true);
    this.serviceOrderService.getServiceOrders(this.buildFilters()).subscribe({
      next: (res) => {
        this.serviceOrders.set(res.data);
        this.total.set(res.total);
        this.currentPage.set(res.current_page);
        this.lastPage.set(res.last_page);
        this.perPage.set(res.per_page);
        this.loading.set(false);
        this.detailNav.onListLoaded();
      },
      error: () => {
        this.loading.set(false);
        this.detailNav.reset();
      },
    });
  }

  /** Auto-refresh tick: list and KPI cards together. */
  refresh(): void {
    this.loadData();
    this.loadSummary();
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

  loadSummary(): void {
    this.serviceOrderService.getSummary(this.buildFilters()).subscribe({
      next: (s) => this.summary.set(s),
    });
  }

  loadFilters(): void {
    this.serviceOrderService.getFilters().subscribe({
      next: (f) => this.filterOptions.set(f),
    });
  }

  applyFilters(): void {
    this.currentPage.set(1);
    this.loadData();
    this.loadSummary();
  }

  resetFilters(): void {
    this.filterSearch.set('');
    this.filterClientId.set(null);
    this.filterProductId.set(null);
    this.filterStatus.set('');
    this.filterPaymentStatus.set('');
    this.filterPaymentMethod.set('');
    this.filterCommercial.set('');
    this.filterDateFrom.set('');
    this.filterDateTo.set('');
    this.sortBy.set('');
    this.sortDirection.set('asc');
    this.applyFilters();
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.lastPage()) return;
    this.currentPage.set(page);
    this.loadData();
  }

  openAddForm(): void {
    this.editingOrder.set(null);
    this.showForm.set(true);
  }

  openDetail(order: ServiceOrder): void {
    this.detailOrder.set(order);
  }

  private loadFullDetail(id: number): void {
    this.loadingDetail.set(true);
    this.serviceOrderService.getServiceOrder(id).subscribe({
      next: (full) => {
        // Ignore a late answer if the user already moved on to another record.
        if (this.detailOrder()?.id !== id) return;
        this.detailOrder.set(full);
        this.loadingDetail.set(false);
      },
      error: () => {
        if (this.detailOrder()?.id !== id) return;
        this.closeDetail();
      },
    });
  }

  closeDetail(): void {
    this.detailOrder.set(null);
    this.loadingDetail.set(false);
    this.detailNav.reset();
  }

  editFromDetail(): void {
    const order = this.detailOrder();
    if (!order) return;
    this.closeDetail();
    this.openEditForm(order);
  }

  openEditForm(order: ServiceOrder): void {
    this.editingOrder.set(null);
    this.loadingEdit.set(true);
    this.showForm.set(true);
    this.serviceOrderService.getServiceOrder(order.id).subscribe({
      next: (full) => {
        this.editingOrder.set(full);
        this.loadingEdit.set(false);
      },
      error: () => {
        this.loadingEdit.set(false);
        this.showForm.set(false);
      },
    });
  }

  orderProductLabels(order: ServiceOrder): string[] {
    return (order.items ?? [])
      .map(it => {
        if (it.item_type === 'part') return it.product_name ?? null;
        return it.service_type ?? null;
      })
      .filter((v): v is string => v !== null && v.trim().length > 0);
  }

  getServiceItems(order: ServiceOrder) {
    return (order.items ?? []).filter(i => i.item_type !== 'part');
  }

  getPartItems(order: ServiceOrder) {
    return (order.items ?? []).filter(i => i.item_type === 'part');
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingOrder.set(null);
  }

  onFormSubmit(payload: ServiceOrderPayload): void {
    const editing = this.editingOrder();
    if (editing) {
      this.serviceOrderService.updateServiceOrder(editing.id, payload).subscribe({
        next: () => {
          this.closeForm();
          this.loadData();
          this.loadSummary();
        },
      });
    } else {
      this.serviceOrderService.createServiceOrder(payload).subscribe({
        next: () => {
          this.closeForm();
          this.loadData();
          this.loadSummary();
        },
      });
    }
  }

  deleteOrder(order: ServiceOrder): void {
    if (!confirm(`Supprimer l'intervention de ${order.client_record?.name || order.vehicle} ?`)) return;
    this.deletingId.set(order.id);
    this.serviceOrderService.deleteServiceOrder(order.id).subscribe({
      next: () => {
        this.deletingId.set(null);
        this.loadData();
        this.loadSummary();
      },
      error: () => this.deletingId.set(null),
    });
  }

  openPayments(order: ServiceOrder): void {
    this.paymentOrder.set(order);
  }

  closePayments(): void {
    this.paymentOrder.set(null);
    this.loadData();
    this.loadSummary();
  }

  buildFilters(): Record<string, string> {
    const f: Record<string, string> = {
      page: String(this.currentPage()),
      per_page: String(this.perPage()),
    };
    if (this.filterSearch()) f['search'] = this.filterSearch();
    if (this.filterClientId()) f['client_id'] = String(this.filterClientId());
    if (this.filterProductId()) f['product_id'] = String(this.filterProductId());
    if (this.filterStatus()) f['status'] = this.filterStatus();
    if (this.filterPaymentStatus()) f['payment_status'] = this.filterPaymentStatus();
    if (this.filterPaymentMethod()) f['payment_method'] = this.filterPaymentMethod();
    if (this.filterCommercial()) f['commercial_id'] = this.filterCommercial();
    if (this.filterDateFrom()) f['date_from'] = this.filterDateFrom();
    if (this.filterDateTo()) f['date_to'] = this.filterDateTo();
    if (this.sortBy()) {
      f['sort_by'] = this.sortBy();
      f['sort_direction'] = this.sortDirection();
    }
    return f;
  }

  get pages(): number[] {
    const current = this.currentPage();
    const last = this.lastPage();
    const range: number[] = [];
    for (let i = Math.max(1, current - 2); i <= Math.min(last, current + 2); i++) {
      range.push(i);
    }
    return range;
  }

  exportOrders(): void {
    this.isExporting.set(true);
    this.exportError.set('');
    const filters = this.buildFilters();
    delete filters['page'];
    delete filters['per_page'];
    delete filters['sort_by'];
    delete filters['sort_direction'];
    this.serviceOrderService.exportOrders(filters).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `service-auto-${new Date().toISOString().slice(0, 10)}.xlsx`;
        a.click();
        URL.revokeObjectURL(url);
        this.isExporting.set(false);
      },
      error: () => {
        this.exportError.set("L'export a échoué. Veuillez réessayer.");
        this.isExporting.set(false);
      },
    });
  }

  updateOrderStatus(order: ServiceOrder, target: any): void {
    const newStatus = target.value;
    if (order.status === newStatus) return;

    const oldStatus = order.status;
    order.status = newStatus as ServiceOrder['status'];

    this.serviceOrderService.updateServiceOrder(order.id, { status: newStatus } as any).subscribe({
      next: () => this.loadData(),
      error: () => {
        order.status = oldStatus;
        alert('Erreur lors de la mise à jour du statut');
      },
    });
  }

  marginPct(order: ServiceOrder): number {
    const base = Number(order.total_amount);
    if (!base) return 0;
    return (Number(order.margin) / base) * 100;
  }

  marginClass(order: ServiceOrder): string {
    const pct = this.marginPct(order);
    if (pct > 20) return 'margin-high';
    if (pct > 10) return 'margin-mid';
    return 'margin-low';
  }

  statusClass(status: ServiceOrderStatus): string {
    switch (status) {
      case 'TERMINE': return 'badge-success';
      case 'ANNULE': return 'badge-danger';
      default: return 'badge-warning';
    }
  }

  paymentClass(ps: PaymentStatus): string {
    switch (ps) {
      case 'PAYE': return 'badge-success';
      case 'PARTIEL': return 'badge-warning';
      default: return 'badge-danger';
    }
  }
}
