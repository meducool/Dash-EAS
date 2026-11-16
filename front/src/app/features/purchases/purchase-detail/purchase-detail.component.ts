import { Component, EventEmitter, HostListener, Input, OnChanges, OnInit, Output, SimpleChanges, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Purchase } from '../../../core/models/purchase.model';
import { Product } from '../../../core/models/product.model';
import { ProductDetailComponent } from '../../products/product-detail/product-detail.component';
import { DocumentPrintComponent, PrintDocument, PrintLine } from '../../../shared/document-print/document-print.component';
import { paymentMethodClass } from '../../../core/constants/payment-method.constants';
import { AuthService } from '../../../core/services/auth.service';
// Return-related calls live only on this (features/purchases) copy of PurchaseService —
// the core/services copy this component otherwise has no need for is not extended with them.
import { PurchaseService as PurchaseReturnsService } from '../data-access/purchase.service';
import { PurchaseReturn } from '../models/purchase.model';
import { isTypingTarget } from '../../../core/utils/detail-navigator';
import { printLineName, tyreSiteName } from '../../../core/utils/print-line-name';

@Component({
  selector: 'app-purchase-detail',
  standalone: true,
  imports: [CommonModule, ProductDetailComponent, DocumentPrintComponent],
  templateUrl: './purchase-detail.component.html',
  styleUrls: ['../../sales/sale-detail/sale-detail.component.scss', './purchase-detail.component.scss']
})
export class PurchaseDetailComponent implements OnInit, OnChanges {
  @Input({ required: true }) purchase!: Purchase;
  @Input() canEdit = false;
  @Input() canReturn = false;
  /** Précédent / Suivant navigation, driven by the parent list page. */
  @Input() hasPrev = false;
  @Input() hasNext = false;
  /** e.g. "12 / 340" — global position in the filtered list. */
  @Input() position: string | null = null;
  @Output() prev = new EventEmitter<void>();
  @Output() next = new EventEmitter<void>();
  @Output() close = new EventEmitter<void>();
  @Output() edit = new EventEmitter<void>();
  @Output() openReturn = new EventEmitter<void>();
  /** Emitted after a return is deleted here — the parent's purchase list and this
   *  modal's own (now stale) status/payment_status/returned_amount need a refresh. */
  @Output() returnsChanged = new EventEmitter<void>();

  viewingProduct = signal<Product | null>(null);
  printDoc = signal<PrintDocument | null>(null);
  returns = signal<PurchaseReturn[]>([]);
  loadingReturns = signal(false);
  readonly paymentMethodClass = paymentMethodClass;

  // Constructor injection (not inject()) so this component can still be
  // instantiated directly with plain mocks in specs, matching the rest of
  // this codebase's no-TestBed convention (see purchase-detail.component.spec.ts).
  constructor(
    private returnsService: PurchaseReturnsService,
    public authService: AuthService,
  ) {}

  ngOnInit(): void {
    this.loadReturns();
  }

  /**
   * The parent swaps the `purchase` input in place when the user steps to the
   * previous/next record (the component instance is kept by `*ngIf`), so the
   * per-record state must be reset and the returns reloaded here — `ngOnInit`
   * only runs once.
   */
  ngOnChanges(changes: SimpleChanges): void {
    const change = changes['purchase'];
    if (!change || change.firstChange) return;
    this.viewingProduct.set(null);
    this.printDoc.set(null);
    this.returns.set([]);
    this.loadReturns();
  }

  /** ← / → step through the list, unless the user is typing or a nested panel is open. */
  @HostListener('document:keydown', ['$event'])
  onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    if (isTypingTarget(event.target) || this.hasNestedPanelOpen()) return;

    if (event.key === 'ArrowLeft' && this.hasPrev) {
      event.preventDefault();
      this.prev.emit();
    } else if (event.key === 'ArrowRight' && this.hasNext) {
      event.preventDefault();
      this.next.emit();
    }
  }

  private hasNestedPanelOpen(): boolean {
    return !!this.viewingProduct() || !!this.printDoc();
  }

  loadReturns(): void {
    this.loadingReturns.set(true);
    this.returnsService.getReturns(this.purchase.id).subscribe({
      next: (data) => {
        this.returns.set(data);
        this.loadingReturns.set(false);
      },
      error: () => this.loadingReturns.set(false),
    });
  }

  deleteReturn(purchaseReturn: PurchaseReturn): void {
    if (!confirm('Supprimer ce retour ? Le stock sera restauré.')) return;
    this.returnsService.deleteReturn(purchaseReturn.id).subscribe({
      next: () => {
        this.loadReturns();
        this.returnsChanged.emit();
      },
      error: () => alert('Erreur lors de la suppression du retour.'),
    });
  }

  openPrint(): void {
    const lines: PrintLine[] = (this.purchase.items || []).map(item => {
      const product = this.getProduct(item);
      return {
        ...printLineName(product, `Produit #${item.product_id}`),
        qty: item.quantity,
        unit_price: Number(item.unit_price ?? 0),
        discount: 0,
        total: Number(item.unit_price ?? 0) * Number(item.quantity),
      };
    });

    this.printDoc.set({
      type: 'purchase',
      doc_number: String(this.purchase.id),
      date: this.purchase.date,
      party_label: 'Fournisseur',
      party_name: this.purchase.supplier?.name || '-',
      party_phone: this.purchase.supplier?.phone || null,
      lines,
      total_ht: Number(this.purchase.total_price ?? 0),
      discount_global: Number(this.purchase.discount ?? 0),
      net_amount: Number(this.purchase.net_amount ?? this.purchase.total_price ?? 0),
      status: this.purchase.status,
      payment_status: this.purchase.payment_status,
      commercial: this.purchase.commercial?.name || null,
    });
  }

  /** « Pneu Continental EcoContact 6 235/55 R19 101T » for a tyre, else the article's name. */
  productTitle(item: any): string {
    const product = this.getProduct(item);
    // Tyre without dimensions: brand + profile; part / service: its name (profile column).
    const brandProfile = product?.type === 'tyre' ? [product.brand?.name, product.profile].filter(Boolean).join(' ') : (product?.profile ?? '');
    return tyreSiteName(product) || brandProfile || product?.reference || `Produit #${item.product_id}`;
  }

  getProduct(item: any): any {
    return item.linkedProduct || item.linked_product;
  }



  openProductView(item: any): void {
    const product = this.getProduct(item);
    if (product) {
      this.viewingProduct.set(product);
    }
  }

  editProductInNewTab(product: Product): void {
    this.viewingProduct.set(null);
    window.open(`/products?id=${product.id}&edit=1`, '_blank', 'noopener');
  }
}
