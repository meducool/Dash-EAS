import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../../core/services/auth.service';
import { Depot, DepotService } from '../../../core/services/depot.service';
import { InventoryTabsComponent } from '../../../shared/inventory-tabs/inventory-tabs.component';

/** "Dépôts" tab of Produits & Inventaire — the list offered by every "Dépôt" picker. */
@Component({
  selector: 'app-depots-page',
  standalone: true,
  imports: [FormsModule, InventoryTabsComponent],
  templateUrl: './depots-page.component.html',
  styleUrls: ['./depots-page.component.scss'],
})
export class DepotsPageComponent implements OnInit {
  private depotService = inject(DepotService);
  readonly authService = inject(AuthService);

  readonly depots = signal<Depot[]>([]);
  readonly loading = signal(false);
  readonly error = signal('');
  readonly newName = signal('');
  readonly saving = signal(false);
  readonly editingId = signal<number | null>(null);
  readonly editingName = signal('');
  readonly totalLots = computed(() => this.depots().reduce((sum, d) => sum + d.stocks_count, 0));

  get canCreate(): boolean {
    return this.authService.hasPermission('create stock');
  }

  get canEdit(): boolean {
    return this.authService.hasPermission('edit stock');
  }

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.depotService.refresh().subscribe({
      next: (list) => {
        this.depots.set(list);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Impossible de charger les dépôts.');
        this.loading.set(false);
      },
    });
  }

  add(): void {
    const name = this.newName().trim();
    if (!name || this.saving()) return;

    this.saving.set(true);
    this.error.set('');
    this.depotService.createDepot(name).subscribe({
      next: (depot) => {
        this.depots.update((list) => [...list, depot].sort((a, b) => a.name.localeCompare(b.name)));
        this.newName.set('');
        this.saving.set(false);
      },
      error: (err) => this.fail(err),
    });
  }

  startRename(depot: Depot): void {
    this.editingId.set(depot.id);
    this.editingName.set(depot.name);
    this.error.set('');
  }

  cancelRename(): void {
    this.editingId.set(null);
  }

  saveRename(depot: Depot): void {
    const name = this.editingName().trim();
    if (!name || this.saving()) return;
    if (name === depot.name) {
      this.cancelRename();
      return;
    }
    if (depot.stocks_count > 0
      && !confirm(`Renommer « ${depot.name} » en « ${name} » ? Les ${depot.stocks_count} lot(s) de stock de ce dépôt seront mis à jour.`)) {
      return;
    }

    this.saving.set(true);
    this.error.set('');
    this.depotService.renameDepot(depot.id, name).subscribe({
      next: (updated) => {
        this.depots.update((list) =>
          list.map((d) => (d.id === updated.id ? updated : d)).sort((a, b) => a.name.localeCompare(b.name)),
        );
        this.editingId.set(null);
        this.saving.set(false);
      },
      error: (err) => this.fail(err),
    });
  }

  remove(depot: Depot): void {
    if (!confirm(`Supprimer le dépôt « ${depot.name} » ?`)) return;

    this.saving.set(true);
    this.error.set('');
    this.depotService.deleteDepot(depot.id).subscribe({
      next: () => {
        this.depots.update((list) => list.filter((d) => d.id !== depot.id));
        this.saving.set(false);
      },
      error: (err) => this.fail(err),
    });
  }

  private fail(err: any): void {
    const errors = err?.error?.errors;
    const first = errors ? (Object.values(errors)[0] as string[] | undefined)?.[0] : null;
    this.error.set(first || err?.error?.message || "L'opération a échoué.");
    this.saving.set(false);
  }
}
