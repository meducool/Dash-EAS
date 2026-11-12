import { Component, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { BrandService } from '../data-access/brand.service';
import { AuthService } from '../../../core/services/auth.service';
import { Brand, BrandPayload, BrandProfile } from '../models/brand.model';
import { BrandFormComponent } from '../components/brand-form/brand-form.component';
import { AutoRefreshControlComponent } from '../../../shared/auto-refresh-control/auto-refresh-control.component';

@Component({
  selector: 'app-brands-page',
  standalone: true,
  imports: [CommonModule, FormsModule, BrandFormComponent, AutoRefreshControlComponent],
  templateUrl: './brands-page.component.html',
  styleUrls: ['./brands-page.component.scss'],
})
export class BrandsPageComponent implements OnInit {
  brands = signal<Brand[]>([]);

  currentPage = signal(1);
  lastPage = signal(1);
  total = signal(0);
  perPage = signal(20);

  filterSearch = signal('');
  sortBy = signal('');
  sortDirection = signal<'asc' | 'desc'>('asc');

  loading = signal(false);
  deletingBrandId = signal<number | null>(null);
  showForm = signal(false);
  editingBrand = signal<Brand | null>(null);

  // ── Profils de pneus de la marque ouverte ──
  profilesBrand = signal<Brand | null>(null);
  profiles = signal<BrandProfile[]>([]);
  loadingProfiles = signal(false);
  profileError = signal('');
  newProfileName = signal('');
  newProfileCode = signal('');
  editingProfileCode = signal('');
  savingProfile = signal(false);
  editingProfileId = signal<number | null>(null);
  editingProfileName = signal('');

  get canEditProfiles(): boolean {
    return this.authService.hasPermission('edit brands');
  }

  openProfiles(brand: Brand): void {
    this.profilesBrand.set(brand);
    this.profiles.set([]);
    this.newProfileName.set('');
    this.editingProfileId.set(null);
    this.profileError.set('');
    this.loadProfiles();
  }

  closeProfiles(): void {
    if (this.savingProfile()) return;
    const changed = this.profilesBrand() !== null;
    this.profilesBrand.set(null);
    if (changed) this.loadData(); // refresh the profile counts
  }

  private loadProfiles(): void {
    const brand = this.profilesBrand();
    if (!brand) return;
    this.loadingProfiles.set(true);
    this.brandService.getProfiles(brand.id).subscribe({
      next: (list) => {
        this.profiles.set(list);
        this.loadingProfiles.set(false);
      },
      error: () => {
        this.profileError.set('Impossible de charger les profils.');
        this.loadingProfiles.set(false);
      },
    });
  }

  addProfile(): void {
    const brand = this.profilesBrand();
    const name = this.newProfileName().trim();
    if (!brand || !name || this.savingProfile()) return;

    this.savingProfile.set(true);
    this.profileError.set('');
    this.brandService.createProfile(brand.id, name, this.newProfileCode()).subscribe({
      next: (profile) => {
        this.profiles.update((list) => [...list, profile].sort((a, b) => a.name.localeCompare(b.name)));
        this.newProfileName.set('');
        this.newProfileCode.set('');
        this.savingProfile.set(false);
      },
      error: (err) => this.onProfileError(err),
    });
  }

  startRename(profile: BrandProfile): void {
    this.editingProfileId.set(profile.id);
    this.editingProfileName.set(profile.name);
    this.editingProfileCode.set(profile.code ?? '');
    this.profileError.set('');
  }

  cancelRename(): void {
    this.editingProfileId.set(null);
  }

  saveRename(profile: BrandProfile): void {
    const name = this.editingProfileName().trim();
    if (!name || this.savingProfile()) return;
    const code = this.editingProfileCode().trim().toUpperCase();
    if (name === profile.name && code === (profile.code ?? '')) {
      this.cancelRename();
      return;
    }
    if (name !== profile.name && profile.products_count > 0
      && !confirm(`Renommer « ${profile.name} » en « ${name} » ? Les ${profile.products_count} produit(s) de cette marque qui portent ce profil seront renommés aussi.`)) {
      return;
    }

    this.savingProfile.set(true);
    this.profileError.set('');
    this.brandService.renameProfile(profile.id, name, code).subscribe({
      next: (updated) => {
        this.profiles.update((list) =>
          list.map((p) => (p.id === updated.id ? updated : p)).sort((a, b) => a.name.localeCompare(b.name)),
        );
        this.editingProfileId.set(null);
        this.savingProfile.set(false);
      },
      error: (err) => this.onProfileError(err),
    });
  }

  removeProfile(profile: BrandProfile): void {
    const note = profile.products_count > 0
      ? `\n${profile.products_count} produit(s) gardent ce nom de profil.`
      : '';
    if (!confirm(`Retirer le profil « ${profile.name} » de la liste ?${note}`)) return;

    this.savingProfile.set(true);
    this.brandService.deleteProfile(profile.id).subscribe({
      next: () => {
        this.profiles.update((list) => list.filter((p) => p.id !== profile.id));
        this.savingProfile.set(false);
      },
      error: (err) => this.onProfileError(err),
    });
  }

  private onProfileError(err: any): void {
    const errors = err?.error?.errors;
    const first = errors ? (Object.values(errors)[0] as string[] | undefined)?.[0] : null;
    this.profileError.set(first || err?.error?.message || "L'opération a échoué.");
    this.savingProfile.set(false);
  }

  constructor(
    private brandService: BrandService,
    public authService: AuthService,
  ) {}

  ngOnInit(): void {
    this.loadData();
  }

  loadData(): void {
    this.loading.set(true);
    const page = Number(this.currentPage() ?? 1) || 1;
    const perPage = Number(this.perPage() ?? 20) || 20;

    const filters: Record<string, string> = {
      page: page.toString(),
      per_page: perPage.toString(),
      sort_by: this.sortBy(),
      sort_direction: this.sortDirection(),
    };

    if (this.filterSearch()) {
      filters['search'] = this.filterSearch();
    }

    this.brandService.getBrands(filters).subscribe({
      next: (response) => {
        this.brands.set(response.data);
        this.currentPage.set(Number(response.current_page ?? 1) || 1);
        this.lastPage.set(Number(response.last_page ?? 1) || 1);
        this.total.set(Number(response.total ?? 0) || 0);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
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
    this.filterSearch.set('');
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
    this.editingBrand.set(null);
    this.showForm.set(true);
  }

  openEditForm(brand: Brand): void {
    this.editingBrand.set(brand);
    this.showForm.set(true);
  }

  closeForm(): void {
    this.showForm.set(false);
    this.editingBrand.set(null);
  }

  onFormSubmit(event: { payload: BrandPayload; logo?: File }): void {
    const editing = this.editingBrand();
    if (editing) {
      this.brandService.updateBrand(editing.id, event.payload, event.logo).subscribe({
        next: () => {
          this.closeForm();
          this.loadData();
        },
      });
    } else {
      this.brandService.createBrand(event.payload, event.logo).subscribe({
        next: () => {
          this.closeForm();
          this.loadData();
        },
      });
    }
  }

  toggleActive(brand: Brand): void {
    this.brandService.toggleActive(brand.id).subscribe({
      next: () => this.loadData(),
    });
  }

  deleteBrand(brand: Brand): void {
    if (!confirm(`Supprimer la marque "${brand.name}" ?`)) {
      return;
    }

    this.deletingBrandId.set(brand.id);

    this.brandService.deleteBrand(brand.id).subscribe({
      next: () => {
        this.deletingBrandId.set(null);
        this.loadData();
      },
      error: () => {
        this.deletingBrandId.set(null);
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
