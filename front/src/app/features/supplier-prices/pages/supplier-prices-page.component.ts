import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { SupplierService } from '../../../core/services/supplier.service';
import { SupplierPriceService } from '../data-access/supplier-price.service';
import { WebstoreTabComponent } from '../components/webstore-tab/webstore-tab.component';
import {
  ColumnMapping,
  PRICE_LIST_FIELD_LABELS,
  PriceListField,
  SupplierPriceBrand,
  SupplierPriceBrandsResponse,
  SupplierPriceFile,
  SupplierPriceFilterOptions,
  SupplierPriceGroup,
  SupplierPriceOffer,
} from '../models/supplier-price.model';

type Tab = 'compare' | 'brands' | 'files' | 'webstore';

interface FileForm {
  id: number | null;
  /** Margin of this price list (%), '' = the default margin. */
  margin: string;
  supplier_id: number | null;
  name: string;
  source_type: 'upload' | 'url';
  source_url: string;
  auto_refresh: boolean;
  mapping: ColumnMapping;
}

/**
 * Tarifs fournisseurs: supplier price lists (Excel files uploaded or read from
 * a link), searched by reference / dimension and compared across suppliers.
 */
@Component({
  selector: 'app-supplier-prices-page',
  standalone: true,
  imports: [FormsModule, DecimalPipe, DatePipe, WebstoreTabComponent],
  templateUrl: './supplier-prices-page.component.html',
  styleUrls: ['./supplier-prices-page.component.scss'],
})
export class SupplierPricesPageComponent implements OnInit {
  private service = inject(SupplierPriceService);
  private supplierService = inject(SupplierService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  readonly authService = inject(AuthService);

  readonly FIELDS = Object.keys(PRICE_LIST_FIELD_LABELS) as PriceListField[];
  readonly FIELD_LABELS = PRICE_LIST_FIELD_LABELS;

  readonly tab = signal<Tab>('compare');

  // ── Comparateur ──────────────────────────────────────────────────────────
  readonly search = signal('');
  readonly filterBrand = signal(''); // "id:12" or "name:XYZ" (brand not in the catalogue)
  readonly filterSupplier = signal('');
  readonly filterRunflat = signal(''); // '' | '1' | '0'
  readonly filterXl = signal('');
  readonly filterSpeed = signal('');
  /** Speed index "or faster" (a W or Y tyre also fits a car homologated V). */
  readonly filterSpeedMin = signal(true);
  readonly filterLoad = signal('');
  readonly filterOptions = signal<SupplierPriceFilterOptions | null>(null);

  // ── Marge (prix de vente = Prix TTC × (1 + %)) ───────────────────────────
  readonly defaultMargin = signal(0);
  readonly canEditMargin = signal(false);
  readonly marginInput = signal('0');
  readonly savingMargin = signal(false);
  readonly marginMessage = signal('');
  readonly activeFilters = computed(() =>
    [this.filterBrand(), this.filterSupplier(), this.filterRunflat(), this.filterXl(), this.filterSpeed(), this.filterLoad()].filter(Boolean).length,
  );
  readonly groups = signal<SupplierPriceGroup[]>([]);
  readonly loading = signal(false);
  readonly error = signal('');
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly truncated = signal(false);
  readonly expanded = signal<Set<string>>(new Set());
  readonly buyingId = signal<number | null>(null);

  // ── Marques ──────────────────────────────────────────────────────────────
  readonly brands = signal<SupplierPriceBrand[]>([]);
  readonly summary = signal<SupplierPriceBrandsResponse['summary'] | null>(null);
  readonly brandFilter = signal('');
  readonly visibleBrands = computed(() => {
    const q = this.brandFilter().trim().toLowerCase();
    return q ? this.brands().filter((b) => (b.name ?? '').toLowerCase().includes(q)) : this.brands();
  });

  // ── Fichiers ─────────────────────────────────────────────────────────────
  readonly files = signal<SupplierPriceFile[]>([]);
  readonly loadingFiles = signal(false);
  readonly suppliers = signal<{ id: number; name: string }[]>([]);
  readonly busyFileId = signal<number | null>(null);
  readonly fileMessage = signal('');

  readonly supplierOptions = computed(() => {
    const seen = new Map<number, string>();
    for (const f of this.files()) if (f.supplier) seen.set(f.supplier.id, f.supplier.name);
    return [...seen.entries()].map(([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name));
  });

  // ── File form (modal) ────────────────────────────────────────────────────
  readonly formOpen = signal(false);
  readonly form = signal<FileForm>(this.emptyForm());
  readonly formFile = signal<File | null>(null);
  readonly formHeaders = signal<Record<string, string> | null>(null);
  readonly formPreview = signal<Record<string, string>[]>([]);
  readonly formError = signal('');
  readonly saving = signal(false);
  readonly headerLetters = computed(() => Object.keys(this.formHeaders() ?? {}));

  get canManage(): boolean {
    return this.authService.hasPermission('manage supplier-prices');
  }

  get canBuy(): boolean {
    return this.authService.hasPermission('create products') && this.authService.hasPermission('create purchases');
  }

  ngOnInit(): void {
    const q = this.route.snapshot.queryParamMap.get('search');
    if (q) this.search.set(q);
    this.loadFiles();
    this.loadBrands();
    this.loadFilterOptions();
    this.loadSettings();
    this.loadGroups();
  }

  loadSettings(): void {
    this.service.settings().subscribe({
      next: (s) => {
        this.defaultMargin.set(s.default_margin);
        this.marginInput.set(String(s.default_margin));
        this.canEditMargin.set(s.can_edit_margin);
      },
    });
  }

  saveDefaultMargin(): void {
    const value = Number(String(this.marginInput()).replace(',', '.'));
    if (!Number.isFinite(value) || value < 0 || value > 500) {
      this.marginMessage.set('Marge entre 0 et 500 %.');
      return;
    }
    this.savingMargin.set(true);
    this.marginMessage.set('');
    this.service.updateSettings(value).subscribe({
      next: (s) => {
        this.defaultMargin.set(s.default_margin);
        this.marginInput.set(String(s.default_margin));
        this.savingMargin.set(false);
        this.marginMessage.set('Marge enregistrée : prix de vente recalculés.');
        this.loadGroups();
      },
      error: (err: HttpErrorResponse) => {
        this.savingMargin.set(false);
        this.marginMessage.set(this.messageOf(err, "La marge n'a pas pu être enregistrée."));
      },
    });
  }

  fileMargin(file: SupplierPriceFile): number {
    return file.margin_percent ?? this.defaultMargin();
  }

  loadFilterOptions(): void {
    this.service.filterOptions().subscribe({ next: (options) => this.filterOptions.set(options) });
  }

  /** Cheapest offer of a group (its selling price is shown on the group line). */
  bestOffer(g: SupplierPriceGroup): SupplierPriceOffer | null {
    return g.offers.find((o) => o.is_best) ?? g.offers[0] ?? null;
  }

  setTab(tab: Tab): void {
    this.tab.set(tab);
  }

  // ── Comparateur ──────────────────────────────────────────────────────────
  runSearch(): void {
    this.page.set(1);
    this.loadGroups();
  }

  resetSearch(): void {
    this.search.set('');
    this.filterBrand.set('');
    this.filterSupplier.set('');
    this.filterRunflat.set('');
    this.filterXl.set('');
    this.filterSpeed.set('');
    this.filterLoad.set('');
    this.runSearch();
  }

  loadGroups(): void {
    this.loading.set(true);
    this.error.set('');
    const brand = this.filterBrand();
    this.service
      .search({
        search: this.search().trim(),
        brand_id: brand.startsWith('id:') ? brand.slice(3) : '',
        brand_name: brand.startsWith('name:') ? brand.slice(5) : '',
        supplier_id: this.filterSupplier(),
        runflat: this.filterRunflat(),
        reinforced: this.filterXl(),
        speed_index: this.filterSpeed(),
        speed_min: this.filterSpeed() && this.filterSpeedMin() ? '1' : '',
        load_index: this.filterLoad(),
        page: String(this.page()),
        per_page: '25',
      })
      .subscribe({
        next: (res) => {
          this.groups.set(res.data);
          this.page.set(res.current_page);
          this.lastPage.set(res.last_page);
          this.total.set(res.total);
          this.truncated.set(res.truncated);
          // A single result opens straight away: that is what the user is looking at.
          this.expanded.set(new Set(res.data.length === 1 ? [res.data[0].key] : []));
          this.loading.set(false);
        },
        error: () => {
          this.error.set('Impossible de charger les tarifs.');
          this.loading.set(false);
        },
      });
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.lastPage() || page === this.page()) return;
    this.page.set(page);
    this.loadGroups();
  }

  toggle(group: SupplierPriceGroup): void {
    this.expanded.update((set) => {
      const next = new Set(set);
      next.has(group.key) ? next.delete(group.key) : next.add(group.key);
      return next;
    });
  }

  isExpanded(group: SupplierPriceGroup): boolean {
    return this.expanded().has(group.key);
  }

  indexes(group: SupplierPriceGroup): string {
    return [group.load_index && group.speed_index ? `${group.load_index}${group.speed_index}` : '', group.reinforced ? 'XL' : '', group.runflat ? 'RF' : '']
      .filter(Boolean)
      .join(' ');
  }

  /** Creates the catalogue product if needed, then opens a new purchase filled with it. */
  buy(offer: SupplierPriceOffer): void {
    if (this.buyingId()) return;
    this.buyingId.set(offer.id);
    this.service.productFor(offer.id).subscribe({
      next: (res) => {
        this.buyingId.set(null);
        this.router.navigate(['/achats'], {
          queryParams: { new: 1, supplier_id: res.supplier_id, product_id: res.product.id, unit_price: res.price },
        });
      },
      error: (err: HttpErrorResponse) => {
        this.buyingId.set(null);
        this.error.set(this.messageOf(err, 'Impossible de créer le produit depuis cette ligne.'));
      },
    });
  }

  // ── Marques ──────────────────────────────────────────────────────────────
  loadBrands(): void {
    this.service.brands().subscribe({
      next: (res) => {
        this.brands.set(res.data);
        this.summary.set(res.summary);
      },
    });
  }

  brandValue(brand: SupplierPriceBrand): string {
    return brand.brand_id ? `id:${brand.brand_id}` : brand.name ? `name:${brand.name}` : '';
  }

  showBrand(brand: SupplierPriceBrand): void {
    if (!brand.name) return;
    this.filterBrand.set(this.brandValue(brand));
    this.tab.set('compare');
    this.runSearch();
  }

  // ── Fichiers ─────────────────────────────────────────────────────────────
  loadFiles(): void {
    this.loadingFiles.set(true);
    this.service.files().subscribe({
      next: (res) => {
        this.files.set(res.data);
        this.loadingFiles.set(false);
      },
      error: () => this.loadingFiles.set(false),
    });
  }

  refresh(file: SupplierPriceFile): void {
    this.busyFileId.set(file.id);
    this.fileMessage.set('');
    this.service.refreshFile(file.id).subscribe({
      next: (updated) => {
        this.replaceFile(updated);
        this.fileMessage.set(`« ${updated.name} » relu : ${updated.rows_count} lignes.`);
        this.afterImport();
      },
      error: (err: HttpErrorResponse) => {
        this.busyFileId.set(null);
        this.fileMessage.set(this.messageOf(err, 'La relecture a échoué.'));
        this.loadFiles();
      },
    });
  }

  toggleActive(file: SupplierPriceFile): void {
    this.busyFileId.set(file.id);
    this.service.updateFile(file.id, { is_active: !file.is_active }).subscribe({
      next: (updated) => {
        this.replaceFile(updated);
        this.afterImport();
      },
      error: () => this.busyFileId.set(null),
    });
  }

  remove(file: SupplierPriceFile): void {
    if (!confirm(`Supprimer le tarif « ${file.name} » et ses ${file.rows_count} lignes ?`)) return;
    this.busyFileId.set(file.id);
    this.service.deleteFile(file.id).subscribe({
      next: () => {
        this.files.update((list) => list.filter((f) => f.id !== file.id));
        this.afterImport();
      },
      error: () => this.busyFileId.set(null),
    });
  }

  // ── File form ────────────────────────────────────────────────────────────
  openCreate(): void {
    this.loadSuppliers();
    this.form.set(this.emptyForm());
    this.formFile.set(null);
    this.formHeaders.set(null);
    this.formPreview.set([]);
    this.formError.set('');
    this.formOpen.set(true);
  }

  openEdit(file: SupplierPriceFile): void {
    this.loadSuppliers();
    this.form.set({
      id: file.id,
      supplier_id: file.supplier_id,
      name: file.name,
      source_type: file.source_type,
      source_url: file.source_url ?? '',
      auto_refresh: file.auto_refresh,
      margin: file.margin_percent !== null && file.margin_percent !== undefined ? String(file.margin_percent) : '',
      mapping: { ...(file.column_mapping ?? {}) },
    });
    this.formFile.set(null);
    this.formHeaders.set(file.headers && Object.keys(file.headers).length ? file.headers : null);
    this.formPreview.set([]);
    this.formError.set('');
    this.formOpen.set(true);
  }

  closeForm(): void {
    if (this.saving()) return;
    this.formOpen.set(false);
  }

  patchForm(patch: Partial<FileForm>): void {
    this.form.update((f) => ({ ...f, ...patch }));
  }

  setMapping(field: PriceListField, letter: string): void {
    this.form.update((f) => ({ ...f, mapping: { ...f.mapping, [field]: letter || undefined } }));
  }

  onFileChosen(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    this.formFile.set(file);
    // A new file may have other columns: the mapping is detected again.
    this.formHeaders.set(null);
    this.formPreview.set([]);
    this.patchForm({ mapping: {} });
    if (file && !this.form().name.trim()) this.patchForm({ name: file.name.replace(/\.[^.]+$/, '') });
  }

  columnLabel(letter: string): string {
    const header = this.formHeaders()?.[letter];
    return header ? `${letter} — ${header}` : letter;
  }

  save(): void {
    const f = this.form();
    if (!f.supplier_id || !f.name.trim()) {
      this.formError.set('Choisissez un fournisseur et donnez un nom au tarif.');
      return;
    }
    if (!f.id && f.source_type === 'upload' && !this.formFile()) {
      this.formError.set('Choisissez le fichier Excel à téléverser.');
      return;
    }
    if (f.source_type === 'url' && !/^https?:\/\//i.test(f.source_url.trim())) {
      this.formError.set('Le lien doit commencer par http:// ou https://.');
      return;
    }

    const marginText = f.margin.trim().replace(',', '.');
    const margin = marginText === '' ? null : Number(marginText);
    if (margin !== null && (!Number.isFinite(margin) || margin < 0 || margin > 500)) {
      this.formError.set('Marge du tarif entre 0 et 500 % (vide = marge par défaut).');
      return;
    }

    const mapping = Object.fromEntries(Object.entries(f.mapping).filter(([, v]) => !!v)) as ColumnMapping;
    const hasMapping = Object.keys(mapping).length > 0;
    const payload = {
      supplier_id: f.supplier_id,
      name: f.name.trim(),
      source_url: f.source_type === 'url' ? f.source_url.trim() : undefined,
      auto_refresh: f.source_type === 'url' ? f.auto_refresh : undefined,
      column_mapping: hasMapping ? mapping : undefined,
      margin_percent: this.canEditMargin() ? margin : undefined,
    };

    this.saving.set(true);
    this.formError.set('');
    const request = f.id
      ? this.service.updateFile(f.id, payload, this.formFile())
      : this.service.createFile({ ...payload, source_type: f.source_type }, this.formFile());

    request.subscribe({
      next: (file) => {
        this.saving.set(false);
        this.formOpen.set(false);
        this.files.update((list) => (list.some((x) => x.id === file.id) ? list.map((x) => (x.id === file.id ? file : x)) : [...list, file]));
        this.fileMessage.set(`« ${file.name} » : ${file.rows_count} lignes lues, dont ${file.identified_count} pneus identifiés.`);
        this.afterImport();
      },
      error: (err: HttpErrorResponse) => {
        this.saving.set(false);
        // Columns not recognised: the API sends them back so they can be mapped here.
        if (err.status === 422 && err.error?.headers) {
          this.formHeaders.set(err.error.headers);
          this.formPreview.set(err.error.preview ?? []);
        }
        this.formError.set(this.messageOf(err, "L'import a échoué."));
      },
    });
  }

  readonly downloadingTemplate = signal(false);
  readonly showGuide = signal(false);

  /** Standard template (all brands of the catalogue) for suppliers to fill in Google Sheets. */
  downloadTemplate(): void {
    if (this.downloadingTemplate()) return;
    this.downloadingTemplate.set(true);
    this.service.downloadTemplate().subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'modele-tarif-fournisseur.xlsx';
        link.click();
        URL.revokeObjectURL(url);
        this.downloadingTemplate.set(false);
        this.showGuide.set(true);
      },
      error: () => {
        this.downloadingTemplate.set(false);
        this.error.set('Impossible de télécharger le modèle.');
      },
    });
  }

  // ── helpers ──────────────────────────────────────────────────────────────
  private afterImport(): void {
    this.busyFileId.set(null);
    this.loadBrands();
    this.loadFilterOptions();
    this.loadGroups();
  }

  private replaceFile(file: SupplierPriceFile): void {
    this.files.update((list) => list.map((f) => (f.id === file.id ? file : f)));
  }

  private loadSuppliers(): void {
    if (this.suppliers().length) return;
    this.supplierService.getSuppliers({ all: true }).subscribe({
      next: (res) => {
        const list = Array.isArray(res) ? res : res.data;
        this.suppliers.set(list.map((s) => ({ id: s.id, name: s.name })).sort((a, b) => a.name.localeCompare(b.name)));
      },
    });
  }

  private emptyForm(): FileForm {
    return { id: null, margin: '', supplier_id: null, name: '', source_type: 'upload', source_url: '', auto_refresh: true, mapping: {} };
  }

  private messageOf(err: HttpErrorResponse, fallback: string): string {
    const errors = err.error?.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors).flat()[0] : null;
    return first || err.error?.message || fallback;
  }
}
