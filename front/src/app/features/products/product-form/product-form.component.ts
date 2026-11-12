import { Component, EventEmitter, Input, OnInit, Output, computed, inject, signal } from '@angular/core';
import { DepotSelectComponent } from '../../../shared/depot-select/depot-select.component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Product, ProductPayload } from '../models/product.model';
import { Stock, StockPayload } from '../../../core/models/stock.model';
import { StockService } from '../../../core/services/stock.service';
import { StockMovementService } from '../../../core/services/stock-movement.service';
import { StockMovement, StockMovementType } from '../../../core/models/stock-movement.model';
import { AuthService } from '../../../core/services/auth.service';
import { BrandService } from '../../brands/data-access/brand.service';
import { parseTyreDescription } from '../../../core/utils/tyre-description';
import { ProductService } from '../data-access/product.service';

@Component({
  selector: 'app-product-form',
  standalone: true,
  imports: [DepotSelectComponent, CommonModule, FormsModule],
  templateUrl: './product-form.component.html',
  styleUrls: ['../../sales/sale-form/sale-form.component.scss', './product-form.component.scss']
})
export class ProductFormComponent implements OnInit {
  @Input() product: Product | null = null;
  @Input() brands: { id: number; name: string }[] = [];
  @Output() save = new EventEmitter<ProductPayload>();
  @Output() cancel = new EventEmitter<void>();

  formData: ProductPayload = {
    profile: '',
    reference: '',
    type: 'tyre',
    brand_id: null,
    description: '',
    unit: 'piece',
    is_active: true,
    // Tyre-specific
    tire_width: null,
    tire_height: null,
    tire_diameter: null,
    tire_load_index: '',
    tire_speed_index: '',
    tire_season: null,
    tire_runflat: false,
    tire_reinforced: false,
    tire_marking: '',
    eu_fuel: null,
    eu_wet_grip: null,
    eu_noise_db: null,
    eu_noise_class: null,
    // Part-specific
    part_category: null,
    oem_reference: '',
    compatibility: '',
    // Service-specific
    service_category: null,
    duration_minutes: null,
    selling_price: null,
  };

  // ── Inventory ──
  stockItems = signal<Stock[]>([]);
  stockLoading = signal(false);
  showStockRow = signal(false);
  editingStockId = signal<number | null>(null);
  editingStockOriginalQty = signal<number | null>(null);
  editingStockQuantity = signal<number>(0);
  stockForm: Partial<StockPayload> = {};
  stockReason = signal<string>('');

  stockQuantityChanged = computed(() => {
    const orig = this.editingStockOriginalQty();
    if (orig === null) return false;
    return this.editingStockQuantity() !== orig;
  });

  // ── Stock Movements History ──
  movements = signal<StockMovement[]>([]);
  movementsLoading = signal(false);
  showMovements = signal(false);

  private brandService = inject(BrandService);
  private productService = inject(ProductService);

  constructor(
    private stockService: StockService,
    private stockMovementService: StockMovementService,
    public authService: AuthService,
  ) {}

  ngOnInit() {
    if (!this.product) {
      this.loadStockOptions();
      this.schedulePreview();
    }
    if (this.product) {
      const tyre = this.product.tyre ?? null;
      const part = this.product.part ?? null;
      const service = this.product.service ?? null;

      this.formData = {
        profile: this.product.profile || '',
        reference: this.product.reference || '',
        type: this.product.type,
        brand_id: this.product.brand_id,
        description: this.product.description || '',
        unit: this.product.unit || 'piece',
        is_active: this.product.is_active,
        // Tyre-specific
        tire_width: tyre?.tire_width ?? null,
        tire_height: tyre?.tire_height ?? null,
        tire_diameter: tyre?.tire_diameter ?? null,
        tire_load_index: tyre?.tire_load_index || '',
        tire_speed_index: tyre?.tire_speed_index || '',
        tire_season: tyre?.tire_season ?? null,
        tire_runflat: tyre?.tire_runflat ?? false,
        tire_reinforced: tyre?.tire_reinforced ?? false,
        tire_marking: tyre?.tire_marking || '',
        eu_fuel: tyre?.eu_fuel ?? null,
        eu_wet_grip: tyre?.eu_wet_grip ?? null,
        eu_noise_db: tyre?.eu_noise_db ?? null,
        eu_noise_class: tyre?.eu_noise_class ?? null,
        // Part-specific
        part_category: part?.category ?? null,
        oem_reference: part?.oem_reference || '',
        compatibility: part?.compatibility || '',
        // Service-specific
        service_category: service?.category ?? null,
        duration_minutes: service?.duration_minutes ?? null,
        selling_price: service?.selling_price ?? null,
      };

      // Services have no stock
      if (this.product.type !== 'service') {
        this.loadStock();
      }
    }
    this.loadBrandProfiles(this.formData.brand_id);
  }

  get isTyre(): boolean {
    return this.formData.type === 'tyre';
  }

  get isPart(): boolean {
    return this.formData.type === 'part';
  }

  get isService(): boolean {
    return this.formData.type === 'service';
  }

  get isEditing(): boolean {
    return !!this.product;
  }

  get totalStock(): number {
    return this.stockItems().reduce((sum, s) => sum + s.quantity, 0);
  }

  onSubmit() {
    const payload = this.withInitialStock(this.formData);
    // A new tyre's reference is generated by the server; parts / services keep what was typed.
    this.save.emit(this.isEditing || !this.isTyre ? payload : { ...payload, reference: null });
  }

  // ── Référence / SKU ──
  // Generated by the server for a new product (preview below); locked once
  // created, except for an Administrator.
  referencePreview = signal('');
  private previewTimer: ReturnType<typeof setTimeout> | null = null;
  private previewRequest = 0;

  get canEditReference(): boolean {
    return this.isEditing && this.authService.hasRole('Administrator');
  }

  /** Recomputes the preview shortly after the last change (debounced). */
  schedulePreview(): void {
    if (this.isEditing || !this.isTyre) return;
    if (this.previewTimer) clearTimeout(this.previewTimer);
    this.previewTimer = setTimeout(() => this.loadPreview(), 350);
  }

  private loadPreview(): void {
    const f = this.formData;
    const params: Record<string, string> = { type: f.type };
    const put = (key: string, value: unknown) => {
      if (value !== null && value !== undefined && String(value).trim() !== '') params[key] = String(value);
    };
    put('brand_id', f.brand_id);
    put('profile', f.profile);
    put('tire_width', f.tire_width);
    put('tire_height', f.tire_height);
    put('tire_diameter', f.tire_diameter);
    put('tire_load_index', f.tire_load_index);
    put('tire_speed_index', f.tire_speed_index);
    if (f.tire_runflat) params['tire_runflat'] = '1';

    const request = ++this.previewRequest;
    this.productService.previewReference(params).subscribe({
      next: (res) => {
        if (request === this.previewRequest) this.referencePreview.set(res.reference);
      },
      error: () => {
        if (request === this.previewRequest) this.referencePreview.set('');
      },
    });
  }

  // ── Remplissage depuis la description ──
  // "205/80R16 104H XL CrossContact ATR" → dimensions, indexes, XL, brand, profile.
  autofillNote = signal('');

  /**
   * Reads the description and fills the tyre fields. On leaving the field only
   * the empty ones are filled (never overwrites what was typed); the button
   * (`overwrite`) replaces them all with what the description says.
   */
  fillFromDescription(overwrite: boolean): void {
    if (!this.isTyre) return;
    const parsed = parseTyreDescription(this.formData.description, this.brands);
    const filled: string[] = [];
    const isEmpty = (v: unknown) => v === null || v === undefined || String(v).trim() === '';

    const set = <K extends keyof ProductPayload>(key: K, value: ProductPayload[K] | undefined, label: string) => {
      if (value === undefined || value === null || value === '') return;
      if (!overwrite && !isEmpty(this.formData[key])) return;
      if (this.formData[key] === value) return;
      this.formData[key] = value;
      filled.push(label);
    };

    set('tire_width', parsed.width, 'Largeur');
    set('tire_height', parsed.height, 'Hauteur');
    set('tire_diameter', parsed.diameter, 'Diamètre');
    set('tire_load_index', parsed.loadIndex, 'Indice de charge');
    set('tire_speed_index', parsed.speedIndex, 'Indice de vitesse');
    set('tire_season', parsed.season, 'Saison');
    set('tire_marking', parsed.marking, 'Marquage');
    if (parsed.reinforced && !this.formData.tire_reinforced) {
      this.formData.tire_reinforced = true;
      filled.push('Renforcé (XL)');
    }
    if (parsed.runflat && !this.formData.tire_runflat) {
      this.formData.tire_runflat = true;
      filled.push('Run Flat');
    }

    const brandChanged = parsed.brandId !== undefined
      && (overwrite || !this.formData.brand_id)
      && Number(this.formData.brand_id) !== parsed.brandId;
    if (brandChanged) {
      this.formData.brand_id = parsed.brandId!;
      filled.push('Marque');
    }
    set('profile', parsed.profile, 'Profil');
    if (brandChanged) {
      this.loadBrandProfiles(parsed.brandId);
    }

    this.autofillNote.set(filled.length ? `Rempli depuis la description : ${filled.join(', ')}.` : '');
    this.schedulePreview();
  }

  // ── Profils de la marque (suggestions) ──
  /** Tyre profiles managed under the chosen brand on the Marques page. */
  brandProfiles = signal<string[]>([]);
  private brandProfilesRequest = 0;

  /** The brand's profiles could not be loaded (e.g. no access): the field falls back to free text. */
  profileLoadFailed = signal(false);
  creatingProfile = signal(false);
  newProfileName = signal('');
  savingProfile = signal(false);
  profileError = signal('');

  get canCreateProfile(): boolean {
    return this.authService.hasPermission('edit brands');
  }

  /** The brand's profiles, plus the product's current profile when it is not in the list. */
  get profileOptions(): string[] {
    const names = this.brandProfiles();
    const current = (this.formData.profile ?? '').trim();
    return current && !names.some((n) => n.toLowerCase() === current.toLowerCase()) ? [current, ...names] : names;
  }

  loadBrandProfiles(brandId: number | null | undefined): void {
    const request = ++this.brandProfilesRequest;
    this.creatingProfile.set(false);
    this.profileError.set('');
    if (!brandId) {
      this.brandProfiles.set([]);
      return;
    }
    this.brandService.getProfiles(Number(brandId)).subscribe({
      next: (list) => {
        if (request !== this.brandProfilesRequest) return;
        this.brandProfiles.set(list.map((p) => p.name));
        this.profileLoadFailed.set(false);
        // "crosscontact atr" read from a description → the list's "CrossContact ATR".
        const current = (this.formData.profile ?? '').trim().toLowerCase();
        const match = current ? list.find((p) => p.name.toLowerCase() === current) : undefined;
        if (match) this.formData.profile = match.name;
        this.schedulePreview();
      },
      error: () => {
        if (request === this.brandProfilesRequest) this.profileLoadFailed.set(true);
      },
    });
  }

  onProfileSelected(value: string): void {
    if (value === '__new__') {
      this.creatingProfile.set(true);
      this.newProfileName.set('');
      this.profileError.set('');
      return;
    }
    this.formData.profile = value || null;
    this.schedulePreview();
  }

  cancelProfileCreate(): void {
    this.creatingProfile.set(false);
    this.profileError.set('');
  }

  /** Creates the profile under the chosen brand (Marques page list) and selects it. */
  createProfile(): void {
    const brandId = Number(this.formData.brand_id);
    const name = this.newProfileName().trim();
    if (!brandId || !name || this.savingProfile()) return;

    const existing = this.brandProfiles().find((n) => n.toLowerCase() === name.toLowerCase());
    if (existing) {
      this.formData.profile = existing;
      this.creatingProfile.set(false);
      return;
    }

    this.savingProfile.set(true);
    this.profileError.set('');
    this.brandService.createProfile(brandId, name).subscribe({
      next: (profile) => {
        this.brandProfiles.update((list) => [...list, profile.name].sort((a, b) => a.localeCompare(b)));
        this.formData.profile = profile.name;
        this.creatingProfile.set(false);
        this.savingProfile.set(false);
        this.schedulePreview();
      },
      error: (err) => {
        const errors = err?.error?.errors;
        const first = errors ? (Object.values(errors)[0] as string[] | undefined)?.[0] : null;
        this.profileError.set(first || err?.error?.message || 'Création impossible.');
        this.savingProfile.set(false);
      },
    });
  }

  // ── Stock initial (creation only) ──
  // A new tyre / part gets its first lot created empty; its location, DOT and
  // origin can be typed here instead of editing the lot afterwards.
  newStock = { depot: '', zone: '', dot: '', made_in: '' };
  /** Existing dépôts / zones / pays, offered as suggestions so spellings stay consistent. */
  stockOptions = signal<{ depots: string[]; zones: string[]; countries: string[] }>({ depots: [], zones: [], countries: [] });

  get showInitialStock(): boolean {
    return !this.isEditing && !this.isService;
  }

  private withInitialStock(payload: ProductPayload): ProductPayload {
    if (!this.showInitialStock) return payload;
    const clean = (value: string) => value.trim() || null;
    const stock = {
      depot: clean(this.newStock.depot),
      zone: clean(this.newStock.zone),
      dot: clean(this.newStock.dot),
      made_in: clean(this.newStock.made_in),
    };
    return Object.values(stock).some((v) => v !== null) ? { ...payload, stock } : payload;
  }

  private loadStockOptions(): void {
    this.stockService.getFilters().subscribe({
      next: (options) => this.stockOptions.set({
        depots: options.depots ?? [],
        zones: options.zones ?? [],
        countries: options.countries ?? [],
      }),
      error: () => { /* suggestions are optional */ },
    });
  }

  // ── Stock Management ──

  loadStock(): void {
    if (!this.product) return;
    this.stockLoading.set(true);
    this.stockService.getStocks({ product_id: this.product.id.toString(), per_page: '200' }).subscribe({
      next: (response) => {
        this.stockItems.set(response.data);
        this.stockLoading.set(false);
      },
      error: () => this.stockLoading.set(false),
    });
  }

  openAddStock(): void {
    this.editingStockId.set(null);
    this.editingStockOriginalQty.set(null);
    this.stockReason.set('');
    this.stockForm = {
      product_id: this.product!.id,
      depot: '',
      zone: '',
      made_in: '',
      dot: '',
      quantity: 0,
      purchase_price: null,
    };
    this.showStockRow.set(true);
  }

  editStock(stock: Stock): void {
    this.editingStockId.set(stock.id);
    this.editingStockOriginalQty.set(stock.quantity);
    this.editingStockQuantity.set(stock.quantity);
    this.stockReason.set('');
    this.stockForm = {
      product_id: this.product!.id,
      depot: stock.depot || '',
      zone: stock.zone || '',
      made_in: stock.made_in || '',
      dot: stock.dot || '',
      quantity: stock.quantity,
      purchase_price: stock.purchase_price,
    };
    this.showStockRow.set(true);
  }

  cancelStockEdit(): void {
    this.showStockRow.set(false);
    this.editingStockId.set(null);
    this.editingStockOriginalQty.set(null);
    this.editingStockQuantity.set(0);
    this.stockReason.set('');
    this.stockForm = {};
  }

  saveStock(): void {
    // Block save if editing and qty changed without reason
    if (this.stockQuantityChanged() && this.stockReason().trim().length < 3) {
      alert('Veuillez saisir un motif (au moins 3 caractères) pour justifier le changement de quantité.');
      return;
    }

    const payload: StockPayload = {
      product_id: this.product!.id,
      depot: (this.stockForm.depot as string) || null,
      zone: (this.stockForm.zone as string) || null,
      made_in: (this.stockForm.made_in as string) || null,
      dot: (this.stockForm.dot as string) || null,
      quantity: this.editingStockId() !== null ? this.editingStockQuantity() : (this.stockForm.quantity || 0),
      purchase_price: this.stockForm.purchase_price ?? null,
    };

    if (this.stockQuantityChanged()) {
      payload.reason = this.stockReason().trim();
    }

    const editId = this.editingStockId();
    if (editId) {
      this.stockService.updateStock(editId, payload).subscribe({
        next: () => { this.cancelStockEdit(); this.loadStock(); this.loadMovementsIfOpen(); },
      });
    } else {
      this.stockService.createStock(payload).subscribe({
        next: () => { this.cancelStockEdit(); this.loadStock(); this.loadMovementsIfOpen(); },
      });
    }
  }

  deleteStock(stock: Stock): void {
    if (confirm(`Supprimer cette entrée stock (Qté: ${stock.quantity}, Dépôt: ${stock.depot || '-'}) ?`)) {
      this.stockService.deleteStock(stock.id).subscribe({
        next: () => { this.loadStock(); this.loadMovementsIfOpen(); },
      });
    }
  }

  // ── Stock Movements ──

  toggleMovements(): void {
    const next = !this.showMovements();
    this.showMovements.set(next);
    if (next && this.movements().length === 0) {
      this.loadMovements();
    }
  }

  loadMovements(): void {
    if (!this.product) return;
    this.movementsLoading.set(true);
    this.stockMovementService.getMovements({ product_id: this.product.id, per_page: 100 }).subscribe({
      next: (res) => {
        this.movements.set(res.data);
        this.movementsLoading.set(false);
      },
      error: () => this.movementsLoading.set(false),
    });
  }

  private loadMovementsIfOpen(): void {
    if (this.showMovements()) {
      this.loadMovements();
    }
  }

  movementTypeLabel(type: StockMovementType): string {
    const labels: Record<StockMovementType, string> = {
      AUTO_CREATE: 'Création produit',
      INITIAL: 'Création initiale',
      IMPORT: 'Import Excel',
      ADJUSTMENT: 'Ajustement',
      DELETION: 'Suppression',
      SALE_OUT: 'Vente',
      SALE_IN: 'Annulation vente',
      PURCHASE_IN: 'Achat',
      PURCHASE_OUT: 'Annulation achat',
      SERVICE_OUT: 'Service Auto',
      SERVICE_IN: 'Annulation service',
    };
    return labels[type] || type;
  }

  movementTypeClass(type: StockMovementType): string {
    const classes: Record<StockMovementType, string> = {
      AUTO_CREATE: 'mv-grey',
      INITIAL: 'mv-grey',
      IMPORT: 'mv-blue',
      ADJUSTMENT: 'mv-orange',
      DELETION: 'mv-dark-red',
      SALE_OUT: 'mv-red',
      SALE_IN: 'mv-green',
      PURCHASE_IN: 'mv-green',
      PURCHASE_OUT: 'mv-red',
      SERVICE_OUT: 'mv-red',
      SERVICE_IN: 'mv-green',
    };
    return classes[type] || '';
  }

  movementReferenceLabel(m: StockMovement): string {
    if (!m.reference_type) return '-';
    if (m.reference_type.endsWith('Sale')) return `Vente #${m.reference_id}`;
    if (m.reference_type.endsWith('Purchase')) return `Achat #${m.reference_id}`;
    if (m.reference_type === 'Import') return 'Import';
    return m.reference_type;
  }
}
