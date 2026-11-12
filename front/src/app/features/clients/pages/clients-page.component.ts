import { CommonModule } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { ClientFormComponent } from '../components/client-form/client-form.component';
import { ClientService } from '../data-access/client.service';
import { Client, ClientBalanceRow, ClientFilters, ClientPayload } from '../models/client.model';
import { AutoRefreshControlComponent } from '../../../shared/auto-refresh-control/auto-refresh-control.component';
import { CityService } from '../../../core/services/city.service';

type ClientTab = 'comptes' | 'soldes';

@Component({
  selector: 'app-clients-page',
  standalone: true,
  imports: [CommonModule, FormsModule, ClientFormComponent, AutoRefreshControlComponent],
  templateUrl: './clients-page.component.html',
  styleUrl: './clients-page.component.scss',
})
export class ClientsPageComponent implements OnInit {
  private readonly clientService = inject(ClientService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  /** Two pages in one: the client accounts, and the clients that still owe money. */
  readonly activeTab = signal<ClientTab>('comptes');
  readonly balances = signal<ClientBalanceRow[]>([]);
  readonly balancesTotal = signal(0);
  readonly loadingBalances = signal(false);
  readonly balancesError = signal('');
  readonly balanceSums = computed(() =>
    this.balances().reduce(
      (acc, row) => ({
        opening_balance: acc.opening_balance + row.opening_balance,
        sales_unpaid: acc.sales_unpaid + row.sales_unpaid,
        service_unpaid: acc.service_unpaid + row.service_unpaid,
      }),
      { opening_balance: 0, sales_unpaid: 0, service_unpaid: 0 },
    ),
  );
  private readonly cityService = inject(CityService);

  readonly clients = signal<Client[]>([]);
  readonly currentPage = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly perPage = signal(100);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly deletingClientId = signal<number | null>(null);
  readonly errorMessage = signal('');
  readonly cities = signal<string[]>([]);
  readonly isExporting = signal(false);
  readonly exportError = signal('');

  readonly isModalOpen = signal(false);
  readonly selectedClient = signal<Client | null>(null);

  filters: ClientFilters = {
    search: '',
    city: '',
    category: '',
    status: 'all',
    page: 1,
    per_page: 100,
  };

  readonly activeClientsCount = computed(() =>
    this.clients().filter((client) => client.is_active !== false).length,
  );

  readonly inactiveClientsCount = computed(() =>
    this.clients().filter((client) => client.is_active === false).length,
  );

  ngOnInit(): void {
    // ?tab=soldes opens the balances directly (bookmarkable, kept on reload).
    if (this.route.snapshot.queryParamMap.get('tab') === 'soldes') {
      this.activeTab.set('soldes');
    }
    this.cityService.getCities().subscribe(cities => this.cities.set(cities));
    this.refreshAll();
  }

  /** Auto-refresh and first load: the list and the balances together. */
  refreshAll(): void {
    this.loadClients();
    this.loadBalances();
  }

  loadBalances(): void {
    this.loadingBalances.set(true);
    this.balancesError.set('');
    this.clientService.getBalances().subscribe({
      next: (res) => {
        this.balances.set(res.rows ?? []);
        this.balancesTotal.set(Number(res.total) || 0);
        this.loadingBalances.set(false);
      },
      error: () => {
        this.balancesError.set('Impossible de charger les soldes clients.');
        this.loadingBalances.set(false);
      },
    });
  }

  setTab(tab: ClientTab): void {
    if (this.activeTab() === tab) return;
    this.activeTab.set(tab);
    this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { tab: tab === 'soldes' ? 'soldes' : null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  viewClientById(id: number): void {
    this.router.navigate(['/clients', id]);
  }

  loadClients(): void {
    this.loading.set(true);
    this.errorMessage.set('');

    const page = Number(this.currentPage() ?? 1) || 1;
    const perPage = Number(this.perPage() ?? 100) || 100;

    this.clientService
      .getClientsResponse({
        ...this.filters,
        page,
        per_page: perPage,
      })
      .subscribe({
        next: (response) => {
          this.clients.set(response.data ?? []);
          this.currentPage.set(Number(response.current_page ?? 1) || 1);
          this.lastPage.set(Number(response.last_page ?? 1) || 1);
          this.total.set(Number(response.total ?? 0) || 0);
          this.perPage.set(Number(response.per_page ?? perPage) || perPage);
          this.loading.set(false);
        },
        error: () => {
          this.errorMessage.set('Impossible de charger les clients pour le moment.');
          this.loading.set(false);
        },
      });
  }

  applyFilters(): void {
    this.currentPage.set(1);
    this.loadClients();
  }

  clearFilters(): void {
    this.currentPage.set(1);
    this.perPage.set(100);
    this.filters = {
      search: '',
      city: '',
      category: '',
      status: 'all',
      page: 1,
      per_page: 100,
    };

    this.loadClients();
  }

  openCreateModal(): void {
    this.selectedClient.set(null);
    this.isModalOpen.set(true);
  }

  openEditModal(client: Client): void {
    this.selectedClient.set(client);
    this.isModalOpen.set(true);
  }

  closeModal(): void {
    this.isModalOpen.set(false);
    this.selectedClient.set(null);
  }

  saveClient(payload: ClientPayload): void {
    const selectedClient = this.selectedClient();
    const request$ = selectedClient
      ? this.clientService.updateClient(selectedClient.id, payload)
      : this.clientService.createClient(payload);

    this.saving.set(true);
    this.errorMessage.set('');

    request$.subscribe({
      next: () => {
        this.saving.set(false);
        this.closeModal();
        this.loadClients();
      },
      error: () => {
        this.saving.set(false);
        this.errorMessage.set(
          selectedClient
            ? 'Impossible de modifier le client pour le moment.'
            : 'Impossible de créer le client pour le moment.',
        );
      },
    });
  }

  deleteClient(client: Client): void {
    const confirmed = window.confirm(`Supprimer le client "${client.name}" ?`);

    if (!confirmed) {
      return;
    }

    this.deletingClientId.set(client.id);
    this.errorMessage.set('');

    this.clientService.deleteClient(client.id).subscribe({
      next: () => {
        this.deletingClientId.set(null);
        this.loadClients();
      },
      error: () => {
        this.deletingClientId.set(null);
        this.errorMessage.set('Impossible de supprimer le client pour le moment.');
      },
    });
  }

  viewClient(client: Client): void {
    this.router.navigate(['/clients', client.id]);
  }

  exportClients(): void {
    this.isExporting.set(true);
    this.exportError.set('');

    this.clientService.exportClients(this.filters).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `clients-${new Date().toISOString().slice(0, 10)}.xlsx`;
        a.click();
        URL.revokeObjectURL(url);
        this.isExporting.set(false);
      },
      error: () => {
        this.exportError.set("L'export des clients a échoué. Veuillez réessayer.");
        this.isExporting.set(false);
      },
    });
  }

  goToPage(page: number): void {
    if (page >= 1 && page <= this.lastPage()) {
      this.currentPage.set(page);
      this.loadClients();
    }
  }

  get pages(): number[] {
    const total = this.lastPage();
    const current = this.currentPage();
    const pages: number[] = [];

    for (let i = Math.max(1, current - 2); i <= Math.min(total, current + 2); i++) {
      pages.push(i);
    }

    return pages;
  }

  trackByClientId(_: number, client: Client): number {
    return client.id;
  }
}
