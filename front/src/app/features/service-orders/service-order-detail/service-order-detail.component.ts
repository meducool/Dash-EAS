import { Component, EventEmitter, HostListener, Input, OnChanges, Output, SimpleChanges, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ServiceOrder } from '../../../core/models/service-order.model';
import { PaymentStatus, ServiceOrderStatus } from '../../../core/constants/status.constants';
import { DocumentPrintComponent, PrintDocument, PrintLine } from '../../../shared/document-print/document-print.component';
import { isTypingTarget } from '../../../core/utils/detail-navigator';

@Component({
  selector: 'app-service-order-detail',
  standalone: true,
  imports: [CommonModule, DocumentPrintComponent],
  templateUrl: './service-order-detail.component.html',
  styleUrl: './service-order-detail.component.scss',
})
export class ServiceOrderDetailComponent implements OnChanges {
  @Input({ required: true }) serviceOrder!: ServiceOrder;
  @Input() canEdit = false;
  @Input() hasPrev = false;
  @Input() hasNext = false;
  /** e.g. "12 / 340" — global position in the filtered list. */
  @Input() position: string | null = null;
  @Output() close = new EventEmitter<void>();
  @Output() edit = new EventEmitter<void>();
  @Output() prev = new EventEmitter<void>();
  @Output() next = new EventEmitter<void>();

  printDoc = signal<PrintDocument | null>(null);

  /**
   * Précédent / Suivant swaps `serviceOrder` while this instance stays alive
   * (kept by `*ngIf`): a print preview opened on the previous record must not
   * survive the switch. The parent also re-sets the same record once its full
   * version is loaded — that must leave the preview alone.
   */
  ngOnChanges(changes: SimpleChanges): void {
    const change = changes['serviceOrder'];
    if (!change || change.firstChange) return;
    if (change.previousValue?.id !== change.currentValue?.id) this.printDoc.set(null);
  }

  /** ← / → step through the records, unless typing in a field or previewing the print. */
  @HostListener('document:keydown', ['$event'])
  onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    if (isTypingTarget(event.target) || this.printDoc()) return;

    if (event.key === 'ArrowLeft' && this.hasPrev) {
      event.preventDefault();
      this.prev.emit();
    } else if (event.key === 'ArrowRight' && this.hasNext) {
      event.preventDefault();
      this.next.emit();
    }
  }

  openPrint(): void {
    const lines: PrintLine[] = (this.serviceOrder.items || []).map(item => {
      if (item.item_type === 'part') {
        return {
          label: item.product_print_name || item.product_name || `Pièce #${item.product_id}`,
          reference: item.product_reference || undefined,
          qty: item.quantity ?? 1,
          unit: 'pièce',
          unit_price: Number(item.unit_price ?? 0),
          total: Number(item.line_total ?? 0),
        };
      }
      return {
        label: item.service_type || 'Prestation',
        qty: item.quantity ?? 1,
        unit_price: Number(item.labor_cost ?? 0),
        total: Number(item.line_total ?? 0),
      };
    });

    const discountAmount = Number(this.serviceOrder.discount ?? 0) > 0
      ? Number(this.serviceOrder.total_amount) - Number(this.serviceOrder.net_amount)
      : undefined;

    this.printDoc.set({
      type: 'service_order',
      doc_number: String(this.serviceOrder.id),
      date: this.serviceOrder.date,
      party_label: 'Client',
      party_name: this.clientName,
      party_phone: this.clientPhone,
      vehicle: this.serviceOrder.vehicle,
      mileage: this.serviceOrder.mileage,
      lines,
      total_ht: Number(this.serviceOrder.total_amount),
      discount_global: discountAmount,
      net_amount: Number(this.serviceOrder.net_amount),
      status: this.serviceOrder.status,
      payment_status: this.serviceOrder.payment_status,
      notes: this.serviceOrder.notes,
      commercial: this.serviceOrder.commercial?.name || null,
    });
  }

  get clientName(): string {
    return this.serviceOrder?.client_record?.name?.trim() || '—';
  }

  get clientPhone(): string {
    return this.serviceOrder?.client_record?.phone?.trim() || '—';
  }

  itemTotal(item: any): number {
    return Number(item.line_total ?? (Number(item.parts_cost || 0) + Number(item.labor_cost || 0)));
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
