import { Component, EventEmitter, HostListener, Input, OnInit, Output, computed, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Product } from '../models/product.model';
import { Stock } from '../../../core/models/stock.model';
import { StockMovement, StockMovementType } from '../../../core/models/stock-movement.model';
import { StockService } from '../../../core/services/stock.service';
import { StockMovementService } from '../../../core/services/stock-movement.service';
import { AuthService } from '../../../core/services/auth.service';
import { ProductImage, ProductImageList, ProductPurchaseHistory, ProductService } from '../../../core/services/product.service';
import { PHOTO_TYPES, shrinkImage } from '../../../core/utils/image-resize';
import { firstValueFrom } from 'rxjs';
import { tyreSiteName } from '../../../core/utils/print-line-name';

@Component({
  selector: 'app-product-detail',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './product-detail.component.html',
  styleUrl: './product-detail.component.scss',
})
export class ProductDetailComponent implements OnInit {
  @Input({ required: true }) product!: Product;
  @Output() close = new EventEmitter<void>();
  @Output() edit = new EventEmitter<Product>();

  stockItems = signal<Stock[]>([]);
  stockLoading = signal(false);
  movements = signal<StockMovement[]>([]);
  movementsLoading = signal(false);
  showMovements = signal(false);
  purchaseHistory = signal<ProductPurchaseHistory | null>(null);
  purchasesLoading = signal(false);
  showPurchases = signal(false);

  // ── Photos ─────────────────────────────────────────────────────────────
  images = signal<ProductImage[]>([]);
  maxImages = signal(6);
  imagesLoading = signal(false);
  /** Files still being sent (placeholders shown in the next empty slots). */
  uploadingCount = signal(0);
  imageError = signal<string | null>(null);
  /** A file is dragged over the photo area. */
  dropActive = signal(false);
  /** Index of the photo being dragged to change the order. */
  dragIndex = signal<number | null>(null);
  /** Photo shown in large (lightbox). */
  viewerIndex = signal<number | null>(null);
  readonly photoAccept = PHOTO_TYPES.join(',');

  emptySlots = computed(() => {
    const free = this.maxImages() - this.images().length - this.uploadingCount();
    return Array.from({ length: Math.max(0, free) }, (_, i) => i);
  });
  uploadingSlots = computed(() => Array.from({ length: this.uploadingCount() }, (_, i) => i));

  constructor(
    private stockService: StockService,
    private stockMovementService: StockMovementService,
    public authService: AuthService,
    private productService: ProductService,
  ) {}

  ngOnInit(): void {
    if (this.product.type !== 'service') {
      this.loadStock();
    }
    this.loadImages();
  }

  get canEditPhotos(): boolean {
    return this.authService.hasPermission('edit products');
  }

  loadImages(): void {
    this.imagesLoading.set(true);
    this.productService.getImages(this.product.id).subscribe({
      next: (res) => { this.setImages(res); this.imagesLoading.set(false); },
      error: () => this.imagesLoading.set(false),
    });
  }

  private setImages(res: ProductImageList): void {
    this.images.set(res.data);
    if (res.max) this.maxImages.set(res.max);
  }

  /** Adds the chosen / dropped files: checked, shrunk, then sent one by one. */
  async addFiles(fileList: FileList | File[] | null | undefined): Promise<void> {
    if (!fileList || !this.canEditPhotos) return;
    this.imageError.set(null);
    const files = Array.from(fileList);
    const errors: string[] = [];

    const accepted = files.filter((f) => {
      if (PHOTO_TYPES.includes(f.type)) return true;
      errors.push(`« ${f.name} » : format non accepté (JPG, PNG ou WebP).`);
      return false;
    });

    const free = this.maxImages() - this.images().length - this.uploadingCount();
    if (accepted.length > free) {
      errors.push(`${this.maxImages()} photos au maximum : ${accepted.length - Math.max(free, 0)} photo(s) non ajoutée(s).`);
      accepted.splice(Math.max(free, 0));
    }

    this.uploadingCount.update((n) => n + accepted.length);
    for (const file of accepted) {
      try {
        const small = await shrinkImage(file);
        const res = await firstValueFrom(this.productService.uploadImages(this.product.id, [small]));
        this.setImages(res);
      } catch (err: any) {
        errors.push(`« ${file.name} » : ${this.uploadErrorMessage(err)}`);
      } finally {
        this.uploadingCount.update((n) => Math.max(0, n - 1));
      }
    }

    if (errors.length) this.imageError.set(errors.join('\n'));
  }

  private uploadErrorMessage(err: any): string {
    if (err?.status === 413) return 'fichier trop lourd pour le serveur.';
    const errors = err?.error?.errors;
    if (errors) {
      const first = Object.values(errors)[0] as string[] | undefined;
      if (first?.length) return first[0];
    }
    return err?.error?.message || 'envoi impossible.';
  }

  onFileInput(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.addFiles(input.files);
    input.value = ''; // choosing the same file again still triggers change
  }

  private isFileDrag(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
  }

  onDragOver(event: DragEvent): void {
    if (!this.canEditPhotos) return;
    // Files from the computer, or a photo moved inside the grid.
    if (this.isFileDrag(event) || this.dragIndex() !== null) {
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = this.isFileDrag(event) ? 'copy' : 'move';
      if (this.isFileDrag(event)) this.dropActive.set(true);
    }
  }

  onDragLeave(event: DragEvent): void {
    const zone = event.currentTarget as HTMLElement | null;
    if (zone && event.relatedTarget instanceof Node && zone.contains(event.relatedTarget)) return;
    this.dropActive.set(false);
  }

  onDrop(event: DragEvent, targetIndex: number | null = null): void {
    if (!this.canEditPhotos) return;
    event.preventDefault();
    event.stopPropagation();
    this.dropActive.set(false);

    const from = this.dragIndex();
    if (from !== null) {
      this.dragIndex.set(null);
      this.movePhoto(from, targetIndex ?? this.images().length - 1);
      return;
    }
    if (this.isFileDrag(event)) this.addFiles(event.dataTransfer?.files);
  }

  onPhotoDragStart(event: DragEvent, index: number): void {
    if (!this.canEditPhotos) return;
    this.dragIndex.set(index);
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', String(index));
    }
  }

  onPhotoDragEnd(): void {
    this.dragIndex.set(null);
  }

  /** Moves a photo to another slot; slot 0 = main photo. */
  movePhoto(from: number, to: number): void {
    const list = [...this.images()];
    if (from === to || from < 0 || to < 0 || from >= list.length || to >= list.length) return;
    const [moved] = list.splice(from, 1);
    list.splice(to, 0, moved);
    const previous = this.images();
    this.images.set(list); // optimistic
    this.productService.reorderImages(this.product.id, list.map((i) => i.id)).subscribe({
      next: (res) => this.setImages(res),
      error: () => { this.images.set(previous); this.imageError.set('Impossible de changer l\'ordre des photos.'); },
    });
  }

  setMainPhoto(index: number): void {
    this.movePhoto(index, 0);
  }

  deletePhoto(image: ProductImage): void {
    if (!confirm('Supprimer cette photo ?')) return;
    this.productService.deleteImage(image.id).subscribe({
      next: (res) => {
        this.setImages(res);
        const viewer = this.viewerIndex();
        if (viewer !== null) this.viewerIndex.set(res.data.length ? Math.min(viewer, res.data.length - 1) : null);
      },
      error: () => this.imageError.set('Impossible de supprimer la photo.'),
    });
  }

  openViewer(index: number): void {
    this.viewerIndex.set(index);
  }

  closeViewer(): void {
    this.viewerIndex.set(null);
  }

  stepViewer(delta: number): void {
    const count = this.images().length;
    const current = this.viewerIndex();
    if (current === null || count === 0) return;
    this.viewerIndex.set((current + delta + count) % count);
  }

  @HostListener('document:keydown', ['$event'])
  onKeydown(event: KeyboardEvent): void {
    if (this.viewerIndex() === null) return;
    if (event.key === 'Escape') { event.stopPropagation(); this.closeViewer(); }
    else if (event.key === 'ArrowLeft') this.stepViewer(-1);
    else if (event.key === 'ArrowRight') this.stepViewer(1);
  }

  /** « Pneu Michelin Pilot Sport 5 225/45 R17 94Y » for a tyre, like on the online shop. */
  get displayName(): string {
    const p = this.product;
    return tyreSiteName(p) || p?.profile || p?.reference || `Produit #${p?.id}`;
  }

  get isTyre(): boolean { return this.product?.type === 'tyre'; }
  get isPart(): boolean { return this.product?.type === 'part'; }
  get isService(): boolean { return this.product?.type === 'service'; }

  get totalStock(): number {
    return this.stockItems().reduce((sum, s) => sum + s.quantity, 0);
  }

  loadStock(): void {
    this.stockLoading.set(true);
    this.stockService.getStocks({ product_id: this.product.id.toString(), per_page: '200' }).subscribe({
      next: (res) => { this.stockItems.set(res.data); this.stockLoading.set(false); },
      error: () => this.stockLoading.set(false),
    });
  }

  toggleMovements(): void {
    const next = !this.showMovements();
    this.showMovements.set(next);
    if (next && this.movements().length === 0) {
      this.loadMovements();
    }
  }

  togglePurchases(): void {
    const next = !this.showPurchases();
    this.showPurchases.set(next);
    if (next && !this.purchaseHistory()) {
      this.purchasesLoading.set(true);
      this.productService.getPurchaseHistory(this.product.id).subscribe({
        next: (history) => { this.purchaseHistory.set(history); this.purchasesLoading.set(false); },
        error: () => this.purchasesLoading.set(false),
      });
    }
  }

  loadMovements(): void {
    this.movementsLoading.set(true);
    this.stockMovementService.getMovements({ product_id: this.product.id, per_page: 100 }).subscribe({
      next: (res) => { this.movements.set(res.data); this.movementsLoading.set(false); },
      error: () => this.movementsLoading.set(false),
    });
  }

  typeLabel(): string {
    switch (this.product?.type) {
      case 'tyre': return 'Pneu';
      case 'part': return 'Pièce';
      case 'service': return 'Service';
      default: return this.product?.type || '-';
    }
  }

  seasonLabel(): string {
    switch (this.product?.tyre?.tire_season) {
      case 'summer': return 'Été';
      case 'winter': return 'Hiver';
      case 'all_season': return '4 Saisons';
      default: return '-';
    }
  }

  partCategoryLabel(): string {
    switch (this.product?.part?.category) {
      case 'brakes': return 'Freinage';
      case 'lubricants': return 'Lubrifiants';
      case 'engine': return 'Moteur';
      case 'suspension': return 'Suspension';
      case 'filters': return 'Filtres';
      case 'electrical': return 'Électrique';
      case 'body': return 'Carrosserie';
      case 'other': return 'Autre';
      default: return this.product?.part?.category || '-';
    }
  }

  serviceCategoryLabel(): string {
    switch (this.product?.service?.category) {
      case 'mechanical': return 'Mécanique';
      case 'oil': return 'Vidange / Huile';
      case 'tires': return 'Pneumatique';
      case 'bodywork': return 'Carrosserie';
      case 'diagnostic': return 'Diagnostic';
      case 'other': return 'Autre';
      default: return this.product?.service?.category || '-';
    }
  }

  unitLabel(): string {
    switch (this.product?.unit) {
      case 'piece': return 'Pièce';
      case 'set': return 'Jeu';
      case 'pair': return 'Paire';
      case 'meter': return 'Mètre';
      case 'liter': return 'Litre';
      default: return this.product?.unit || '-';
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
