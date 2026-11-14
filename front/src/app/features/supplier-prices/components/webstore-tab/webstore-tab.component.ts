import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { AuthService } from '../../../../core/services/auth.service';
import { ProductService } from '../../../../core/services/product.service';
import { Product } from '../../../../core/models/product.model';
import { WebstoreService } from '../../data-access/webstore.service';
import { WebstoreProposalsComponent } from '../webstore-proposals/webstore-proposals.component';
import { WebstoreHistoryComponent } from '../webstore-history/webstore-history.component';
import {
  WEBSTORE_STATUS_HELP,
  WEBSTORE_STATUS_LABELS,
  WOO_STATUS_LABELS,
  WOO_STOCK_LABELS,
  WebstoreMatchStatus,
  WebstoreReportRow,
  WebstoreSettings,
  WebstoreStatus,
  WebstoreSummary,
} from '../../models/webstore.model';

interface Notice { kind: 'ok' | 'error' | 'info'; text: string }

/**
 * « 🌐 Boutique en ligne » tab of Tarifs fournisseurs — step 1 (read only):
 * connection to the WooCommerce shop, catalogue read and SKU matching report.
 */
@Component({
  selector: 'app-webstore-tab',
  standalone: true,
  imports: [FormsModule, DecimalPipe, DatePipe, WebstoreProposalsComponent, WebstoreHistoryComponent],
  templateUrl: './webstore-tab.component.html',
  styleUrls: ['./webstore-tab.component.scss'],
})
export class WebstoreTabComponent implements OnInit {
  private service = inject(WebstoreService);
  private productService = inject(ProductService);
  readonly authService = inject(AuthService);

  readonly STATUSES: WebstoreMatchStatus[] = ['matched', 'site_only', 'pos_only', 'no_sku', 'duplicate'];
  readonly STATUS_LABELS = WEBSTORE_STATUS_LABELS;
  readonly STATUS_HELP = WEBSTORE_STATUS_HELP;

  readonly canManage = this.authService.hasPermission('manage webstore');

  /** Sub-view: SKU matching report (step 1) or proposals to approve (step 2). */
  readonly view = signal<'report' | 'proposals' | 'history'>('report');
  /** Batch opened from the history (read only), null = the open batch. */
  readonly historyBatchId = signal<number | null>(null);

  /** Open batch counts and last morning run (banner + badge). */
  readonly shopStatus = signal<WebstoreStatus | null>(null);
  readonly preparing = signal(false);
  readonly toDecide = computed(() => this.shopStatus()?.batch?.statuses.proposed ?? 0);

  // ── Connexion ────────────────────────────────────────────────────────────
  readonly settings = signal<WebstoreSettings | null>(null);
  readonly showSettings = signal(false);
  readonly urlInput = signal('');
  readonly keyInput = signal('');
  readonly secretInput = signal('');
  readonly marginInput = signal('');
  readonly createStatusInput = signal<'draft' | 'publish'>('draft');
  readonly autoPrepareInput = signal(false);
  readonly saving = signal(false);
  readonly testing = signal(false);
  readonly pulling = signal(false);
  readonly notice = signal<Notice | null>(null);

  // ── Rapport ──────────────────────────────────────────────────────────────
  readonly status = signal<WebstoreMatchStatus>('matched');
  readonly search = signal('');
  readonly page = signal(1);
  readonly lastPage = signal(1);
  readonly total = signal(0);
  readonly rows = signal<WebstoreReportRow[]>([]);
  readonly summary = signal<WebstoreSummary | null>(null);
  readonly loading = signal(false);
  readonly reportError = signal('');

  // ── Association by hand (anti-duplication) ──────────────────────────────
  /** Site product whose article is being searched. */
  readonly linkingWooId = signal<number | null>(null);
  readonly linkSearch = signal('');
  readonly linkResults = signal<Product[]>([]);
  readonly linkBusy = signal(false);

  readonly hasCatalogue = computed(() => !!this.summary()?.last_pull_at);
  readonly isPosOnly = computed(() => this.status() === 'pos_only');

  ngOnInit(): void {
    if (this.canManage) this.loadSettings();
    this.loadReport();
    this.loadStatus();
  }

  // ── Connexion ────────────────────────────────────────────────────────────
  loadSettings(): void {
    this.service.settings().subscribe({
      next: (s) => {
        this.applySettings(s);
        // First visit: open the connection form straight away.
        if (!s.has_credentials) this.showSettings.set(true);
      },
    });
  }

  private applySettings(s: WebstoreSettings): void {
    this.settings.set(s);
    this.urlInput.set(s.store_url ?? '');
    this.marginInput.set(s.margin_percent != null ? String(s.margin_percent) : '');
    this.createStatusInput.set(s.create_status ?? 'draft');
    this.autoPrepareInput.set(!!s.auto_prepare);
    this.keyInput.set('');
    this.secretInput.set('');
  }

  saveSettings(): void {
    const margin = this.marginInput().trim().replace(',', '.');
    if (margin !== '' && (!Number.isFinite(Number(margin)) || Number(margin) < 0)) {
      this.notice.set({ kind: 'error', text: 'Marge invalide.' });
      return;
    }
    this.saving.set(true);
    this.notice.set(null);
    this.service.updateSettings({
      store_url: this.urlInput().trim() || null,
      consumer_key: this.keyInput().trim() || null,
      consumer_secret: this.secretInput().trim() || null,
      margin_percent: margin === '' ? null : Number(margin),
      create_status: this.createStatusInput(),
      auto_prepare: this.autoPrepareInput(),
    }).subscribe({
      next: (s) => {
        this.applySettings(s);
        this.saving.set(false);
        this.notice.set({ kind: 'ok', text: 'Réglages enregistrés.' });
        this.loadReport();
      },
      error: (err) => { this.saving.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  clearCredentials(): void {
    if (!confirm('Effacer les clés API enregistrées ?')) return;
    this.service.updateSettings({ clear_credentials: true }).subscribe({
      next: (s) => { this.applySettings(s); this.notice.set({ kind: 'info', text: 'Clés API effacées.' }); },
      error: (err) => this.notice.set({ kind: 'error', text: this.errorText(err) }),
    });
  }

  testConnection(): void {
    this.testing.set(true);
    this.notice.set(null);
    this.service.test().subscribe({
      next: (res) => {
        this.testing.set(false);
        this.notice.set({ kind: 'ok', text: `Connexion réussie : ${res.products_total} produit(s) sur le site.` });
      },
      error: (err) => { this.testing.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  pull(): void {
    this.pulling.set(true);
    this.notice.set({ kind: 'info', text: 'Lecture du catalogue du site… (rien n\'est modifié sur le site)' });
    this.service.pull().subscribe({
      next: (summary) => {
        this.pulling.set(false);
        this.summary.set(summary);
        this.notice.set({ kind: 'ok', text: `${summary.site_total} produit(s) lus sur le site, ${summary.matched} correspondant(s) par SKU.` });
        this.page.set(1);
        this.loadReport();
      },
      error: (err) => { this.pulling.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  // ── Préparation du matin ─────────────────────────────────────────────────
  loadStatus(): void {
    this.service.status().subscribe({ next: (s) => this.shopStatus.set(s) });
  }

  /** Same as the 06:30 run, now: reads the site and prepares a batch. Publishes nothing. */
  prepareNow(): void {
    this.preparing.set(true);
    this.notice.set({ kind: 'info', text: 'Préparation : lecture du catalogue du site puis calcul des propositions… (rien n\'est publié)' });
    this.service.prepareNow().subscribe({
      next: (res) => {
        this.preparing.set(false);
        this.notice.set({ kind: res.status === 'ok' ? 'ok' : 'error', text: res.message });
        this.loadStatus();
        this.loadReport();
        if (res.status === 'ok') this.openView('proposals');
      },
      error: (err) => { this.preparing.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  openView(view: 'report' | 'proposals' | 'history'): void {
    this.historyBatchId.set(null);
    this.view.set(view);
    this.loadStatus();
  }

  openHistoryBatch(id: number): void {
    this.historyBatchId.set(id);
    this.view.set('proposals');
  }

  // ── Rapport ──────────────────────────────────────────────────────────────
  setStatus(status: WebstoreMatchStatus): void {
    this.status.set(status);
    this.page.set(1);
    this.loadReport();
  }

  runSearch(): void {
    this.page.set(1);
    this.loadReport();
  }

  goToPage(page: number): void {
    this.page.set(page);
    this.loadReport();
  }

  loadReport(): void {
    this.loading.set(true);
    this.reportError.set('');
    this.service.report({ status: this.status(), search: this.search().trim(), page: this.page(), per_page: 50 }).subscribe({
      next: (res) => {
        this.rows.set(res.data);
        this.summary.set(res.summary);
        this.lastPage.set(res.meta.last_page);
        this.total.set(res.meta.total);
        this.loading.set(false);
      },
      error: (err) => { this.loading.set(false); this.reportError.set(this.errorText(err)); },
    });
  }

  startLink(wooId: number): void {
    this.linkingWooId.set(wooId);
    this.linkSearch.set('');
    this.linkResults.set([]);
  }

  cancelLink(): void {
    this.linkingWooId.set(null);
  }

  searchArticles(): void {
    const q = this.linkSearch().trim();
    if (q.length < 2) return;
    this.productService.getProducts({ search: q, per_page: '8' }).subscribe({
      next: (res) => this.linkResults.set(res.data.filter((p) => p.type !== 'service')),
      error: () => this.linkResults.set([]),
    });
  }

  /** Same product on both sides: no creation, the site SKU becomes the POS reference (after approval). */
  link(wooId: number, productId: number): void {
    this.linkBusy.set(true);
    this.service.link(wooId, productId).subscribe({
      next: (res) => {
        this.linkBusy.set(false);
        this.linkingWooId.set(null);
        this.summary.set(res.summary);
        this.notice.set({ kind: 'ok', text: 'Produit associé. Si son SKU diffère de la référence POS, la correction sera proposée au prochain calcul.' });
        this.loadReport();
      },
      error: (err) => { this.linkBusy.set(false); this.notice.set({ kind: 'error', text: this.errorText(err) }); },
    });
  }

  unlink(wooId: number): void {
    if (!confirm('Dissocier ce produit du site de son article POS ?')) return;
    this.service.unlink(wooId).subscribe({
      next: (res) => { this.summary.set(res.summary); this.notice.set({ kind: 'info', text: 'Association retirée.' }); this.loadReport(); },
      error: (err) => this.notice.set({ kind: 'error', text: this.errorText(err) }),
    });
  }

  linkLabel(link: string | null | undefined): string {
    return link === 'manual' ? 'associé à la main' : link === 'created' ? 'créé par le POS' : link === 'sku' ? 'même SKU' : '';
  }

  count(status: WebstoreMatchStatus): number {
    return this.summary()?.[status] ?? 0;
  }

  wooStatus(status: string | null): string {
    return status ? (WOO_STATUS_LABELS[status] ?? status) : '-';
  }

  wooStock(row: WebstoreReportRow): string {
    const woo = row.woo;
    if (!woo?.stock_status) return '-';
    const label = WOO_STOCK_LABELS[woo.stock_status] ?? woo.stock_status;
    return woo.stock_quantity != null ? `${label} (${woo.stock_quantity})` : label;
  }

  private errorText(err: unknown): string {
    if (err instanceof HttpErrorResponse) {
      const errors = err.error?.errors as Record<string, string[]> | undefined;
      const first = errors ? Object.values(errors)[0]?.[0] : null;
      if (first) return first;
      if (err.status === 429) return 'Trop de tentatives : réessayez dans une minute.';
      if (err.error?.message) return err.error.message;
    }
    return 'Opération impossible.';
  }
}
