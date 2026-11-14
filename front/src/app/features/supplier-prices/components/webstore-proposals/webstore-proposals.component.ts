import { Component, Input, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { AuthService } from '../../../../core/services/auth.service';
import { WebstoreService } from '../../data-access/webstore.service';
import {
  WEBSTORE_ALERT_LABELS,
  WEBSTORE_CHANGE_STATUS_LABELS,
  WOO_STOCK_LABELS,
  WebstoreAlert,
  WebstoreBatch,
  WebstoreChange,
  WebstoreChangeAction,
  WebstoreChangeStatus,
} from '../../models/webstore.model';

interface Notice { kind: 'ok' | 'error' | 'info'; text: string }

/**
 * Boutique en ligne — step 2: proposed changes for the WooCommerce shop
 * (price from the cheapest supplier offer + shop margin, availability, photos),
 * approved or rejected line by line. Nothing is sent to the site here.
 */
@Component({
  selector: 'app-webstore-proposals',
  standalone: true,
  imports: [FormsModule, DecimalPipe, DatePipe],
  templateUrl: './webstore-proposals.component.html',
  styleUrls: ['./webstore-proposals.component.scss'],
})
export class WebstoreProposalsComponent implements OnInit {
  private service = inject(WebstoreService);
  readonly authService = inject(AuthService);

  readonly ALERTS = Object.keys(WEBSTORE_ALERT_LABELS) as WebstoreAlert[];
  readonly ALERT_LABELS = WEBSTORE_ALERT_LABELS;
  readonly STATUS_LABELS = WEBSTORE_CHANGE_STATUS_LABELS;
  readonly STATUS_TABS: (WebstoreChangeStatus | '')[] = ['proposed', 'approved', 'rejected', 'published', 'failed', ''];
  readonly TAB_LABELS: Record<string, string> = {
    proposed: 'À décider', approved: 'Approuvées', rejected: 'Rejetées', published: 'Publiées', failed: 'Échecs', all: 'Toutes',
  };

  /** An older batch from the history: shown read only. null = the open batch. */
  @Input() set batchId(value: number | null) { this.batchIdSig.set(value ?? null); }
  private readonly batchIdSig = signal<number | null>(null);
  readonly readOnly = computed(() => this.batchIdSig() !== null && this.batch()?.status !== 'open');

  private readonly mayGenerate = this.authService.hasPermission('manage webstore');
  private readonly mayApprove = this.authService.hasPermission('approve webstore');
  readonly canGenerate = computed(() => this.mayGenerate && !this.readOnly());
  readonly canApprove = computed(() => this.mayApprove && !this.readOnly());

  readonly batch = signal<WebstoreBatch | null>(null);
  readonly rows = signal<WebstoreChange[]>([]);
  readonly loading = signal(false);
  readonly generating = signal(false);
  readonly deciding = signal(false);
  readonly publishing = signal(false);
  readonly notice = signal<Notice | null>(null);

  readonly status = signal<WebstoreChangeStatus | ''>('proposed');
  readonly action = signal<WebstoreChangeAction | ''>('');
  readonly alert = signal<WebstoreAlert | 'none' | 'any' | ''>('');
  readonly search = signal('');
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);

  /** Lines ticked for a bulk decision. */
  readonly selected = signal<ReadonlySet<number>>(new Set());
  /** Line whose price is being typed, and the typed value. */
  readonly editingId = signal<number | null>(null);
  readonly priceInput = signal('');

  readonly selectedCount = computed(() => this.selected().size);
  readonly allSelected = computed(() => {
    const rows = this.rows();
    return rows.length > 0 && rows.every((r) => this.selected().has(r.id));
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.service.changes({
      status: this.status(), action: this.action(), alert: this.alert(), search: this.search().trim(), page: this.page(), per_page: 50,
      batch_id: this.batchIdSig(),
    }).subscribe({
      next: (res) => {
        this.rows.set(res.data);
        this.batch.set(res.batch);
        this.lastPage.set(res.meta.last_page);
        this.total.set(res.meta.total);
        this.selected.set(new Set());
        this.loading.set(false);
      },
      error: (err) => { this.loading.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  applyFilters(): void {
    this.page.set(1);
    this.load();
  }

  setStatus(status: WebstoreChangeStatus | ''): void {
    this.status.set(status);
    this.applyFilters();
  }

  goToPage(page: number): void {
    this.page.set(page);
    this.load();
  }

  generate(): void {
    const approved = this.batch()?.statuses.approved ?? 0;
    const pending = this.batch()?.statuses.proposed ?? 0;
    if (approved + pending > 0
      && !confirm(`Recalculer les propositions ? Les ${pending} ligne(s) à décider et ${approved} ligne(s) approuvée(s) non publiée(s) du lot actuel seront remplacées.`)) {
      return;
    }
    this.generating.set(true);
    this.notice.set(null);
    this.service.generate().subscribe({
      next: (res) => {
        this.generating.set(false);
        const c = res.batch.counts;
        this.notice.set({ kind: 'ok', text: `${c.update} mise(s) à jour et ${c.create} création(s) proposées, dont ${c.with_alerts} avec alerte. ${c.unchanged} produit(s) déjà à jour.` });
        this.status.set('proposed');
        this.applyFilters();
      },
      error: (err) => { this.generating.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  // ── Selection ────────────────────────────────────────────────────────────
  isSelected(id: number): boolean {
    return this.selected().has(id);
  }

  toggle(id: number): void {
    const next = new Set(this.selected());
    if (next.has(id)) next.delete(id); else next.add(id);
    this.selected.set(next);
  }

  toggleAll(): void {
    this.selected.set(this.allSelected() ? new Set() : new Set(this.rows().map((r) => r.id)));
  }

  // ── Decisions ────────────────────────────────────────────────────────────
  decide(decision: 'approve' | 'reject' | 'reset', ids: number[] = [...this.selected()]): void {
    if (!ids.length) return;
    this.run(decision, ids, 'ids');
  }

  approveAllWithoutAlert(): void {
    if (!confirm('Approuver toutes les lignes à décider qui n\'ont aucune alerte (toutes pages confondues) ?')) return;
    this.run('approve', [], 'no_alerts');
  }

  private run(decision: 'approve' | 'reject' | 'reset', ids: number[], scope: 'ids' | 'no_alerts'): void {
    this.deciding.set(true);
    this.service.decide(decision, ids, scope).subscribe({
      next: (res) => {
        this.deciding.set(false);
        const label = { approve: 'approuvée(s)', reject: 'rejetée(s)', reset: 'remise(s) à décider' }[decision];
        this.notice.set({ kind: 'ok', text: `${res.updated} ligne(s) ${label}.` });
        this.load();
      },
      error: (err) => { this.deciding.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  /** Sends the approved lines to the shop — the only call that writes on the site. */
  publish(): void {
    const count = this.batch()?.statuses.approved ?? 0;
    if (!count) return;
    if (!confirm(`Publier ${count} ligne(s) approuvée(s) sur la boutique en ligne ?

Les prix, disponibilités, photos et créations approuvés seront envoyés au site. Un produit modifié sur le site depuis la lecture du catalogue ne sera pas écrasé.`)) {
      return;
    }
    this.publishing.set(true);
    this.notice.set({ kind: 'info', text: 'Publication en cours… ne fermez pas la page.' });
    this.service.publish().subscribe({
      next: (res) => {
        this.publishing.set(false);
        const parts = [`${res.published} produit(s) publié(s)`];
        if (res.failed) parts.push(`${res.failed} échec(s) — voir l'onglet « Échecs »`);
        if (res.warnings) parts.push(`${res.warnings} avec avertissement (photos)`);
        if (res.stopped) parts.push(`publication arrêtée : ${res.stopped}`);
        this.notice.set({ kind: res.failed || res.stopped ? 'error' : 'ok', text: parts.join(' · ') + '.' });
        this.status.set(res.failed ? 'failed' : 'published');
        this.applyFilters();
      },
      error: (err) => { this.publishing.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  startPrice(row: WebstoreChange): void {
    this.editingId.set(row.id);
    this.priceInput.set(row.final_price != null ? String(row.final_price) : '');
  }

  cancelPrice(): void {
    this.editingId.set(null);
  }

  savePrice(row: WebstoreChange, reset = false): void {
    const raw = this.priceInput().trim().replace(',', '.');
    const price = reset ? null : Number(raw);
    if (!reset && (raw === '' || !Number.isFinite(price) || (price as number) <= 0)) {
      this.notice.set({ kind: 'error', text: 'Prix invalide.' });
      return;
    }
    this.service.setPrice(row.id, price).subscribe({
      next: () => {
        this.editingId.set(null);
        this.notice.set({ kind: 'info', text: 'Prix enregistré : la ligne est de nouveau « à décider », approuvez-la pour la valider.' });
        this.load();
      },
      error: (err) => this.notice.set({ kind: 'error', text: this.errorText(err) }),
    });
  }

  // ── Display helpers ──────────────────────────────────────────────────────
  canDecide(row: WebstoreChange): boolean {
    return this.canApprove() && ['proposed', 'approved', 'rejected', 'failed'].includes(row.status);
  }

  /** Price change in % against the site price (null for a creation or no site price). */
  priceDelta(row: WebstoreChange): number | null {
    const old = row.current?.price;
    const next = row.final_price;
    if (old == null || old <= 0 || next == null) return null;
    return ((next - old) / old) * 100;
  }

  stockLabel(values: { stock_status?: string | null; stock_quantity?: number | null } | null | undefined): string {
    if (!values?.stock_status) return '-';
    const label = WOO_STOCK_LABELS[values.stock_status] ?? values.stock_status;
    return values.stock_status === 'instock' && values.stock_quantity != null ? `${label} (${values.stock_quantity})` : label;
  }

  private errorText(err: unknown): string {
    if (err instanceof HttpErrorResponse) {
      const errors = err.error?.errors as Record<string, string[]> | undefined;
      const first = errors ? Object.values(errors)[0]?.[0] : null;
      if (first) return first;
      if (err.status === 403) return 'Action réservée à l\'administrateur.';
      if (err.status === 429) return 'Trop de tentatives : réessayez dans une minute.';
      if (err.error?.message) return err.error.message;
    }
    return 'Opération impossible.';
  }
}
