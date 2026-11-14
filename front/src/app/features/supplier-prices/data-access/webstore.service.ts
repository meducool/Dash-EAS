import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../../environments/environment';
import {
  WebstoreAutoResult,
  WebstoreBatch,
  WebstoreBatchHistory,
  WebstoreChangesFilters,
  WebstoreChangesResponse,
  WebstorePublishResult,
  WebstoreReport,
  WebstoreSettings,
  WebstoreSettingsPayload,
  WebstoreStatus,
  WebstoreSummary,
} from '../models/webstore.model';

/** Boutique en ligne (WooCommerce): connection settings, catalogue read, SKU report. */
@Injectable({ providedIn: 'root' })
export class WebstoreService {
  private http = inject(HttpClient);
  private apiUrl = `${environment.apiUrl}/webstore`;

  settings(): Observable<WebstoreSettings> {
    return this.http.get<WebstoreSettings>(`${this.apiUrl}/settings`);
  }

  /** Empty key / secret = keep the saved ones. */
  updateSettings(payload: WebstoreSettingsPayload): Observable<WebstoreSettings> {
    return this.http.put<WebstoreSettings>(`${this.apiUrl}/settings`, payload);
  }

  test(): Observable<{ ok: boolean; products_total: number }> {
    return this.http.post<{ ok: boolean; products_total: number }>(`${this.apiUrl}/test`, {});
  }

  /** Reads the whole site catalogue (read only on the site) and matches it by SKU. */
  pull(): Observable<WebstoreSummary> {
    return this.http.post<WebstoreSummary>(`${this.apiUrl}/pull`, {});
  }

  report(filters: { status: string; search?: string; page?: number; per_page?: number }): Observable<WebstoreReport> {
    const params = Object.fromEntries(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null).map(([k, v]) => [k, String(v)]),
    );
    return this.http.get<WebstoreReport>(`${this.apiUrl}/report`, { params: new HttpParams({ fromObject: params }) });
  }

  // ── Step 2: proposals + approval ────────────────────────────────────────

  /** Lines of the open batch of proposals (+ the batch). */
  changes(filters: WebstoreChangesFilters): Observable<WebstoreChangesResponse> {
    const params = Object.fromEntries(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null).map(([k, v]) => [k, String(v)]),
    );
    return this.http.get<WebstoreChangesResponse>(`${this.apiUrl}/changes`, { params: new HttpParams({ fromObject: params }) });
  }

  /** Computes a new batch; the previous open one (undecided + approved lines) is replaced. */
  generate(): Observable<{ batch: WebstoreBatch }> {
    return this.http.post<{ batch: WebstoreBatch }>(`${this.apiUrl}/batches`, {});
  }

  /** scope 'no_alerts' = approve every undecided line without alert (ids ignored). */
  decide(decision: 'approve' | 'reject' | 'reset', ids: number[], scope: 'ids' | 'no_alerts' = 'ids'): Observable<{ updated: number; batch: WebstoreBatch | null }> {
    return this.http.post<{ updated: number; batch: WebstoreBatch | null }>(`${this.apiUrl}/changes/decide`, { decision, ids, scope });
  }

  /** Price typed by the approver (null = back to the computed price); the line must be approved again. */
  setPrice(changeId: number, price: number | null): Observable<{ id: number; status: string; price_override: number | null; final_price: number | null; alerts: string[] }> {
    return this.http.put<{ id: number; status: string; price_override: number | null; final_price: number | null; alerts: string[] }>(`${this.apiUrl}/changes/${changeId}/price`, { price });
  }

  // ── Step 3: publishing ──────────────────────────────────────────────────

  /** Sends the approved lines of the open batch to the shop (writes on the site). */
  publish(): Observable<WebstorePublishResult> {
    return this.http.post<WebstorePublishResult>(`${this.apiUrl}/publish`, {});
  }

  // ── Step 4: morning preparation, status, history ────────────────────────

  /** Open batch counts + result of the last morning run (banner, badge). */
  status(): Observable<WebstoreStatus> {
    return this.http.get<WebstoreStatus>(`${this.apiUrl}/status`);
  }

  /** Runs the morning preparation now: catalogue read + proposals. Publishes nothing. */
  prepareNow(): Observable<WebstoreAutoResult> {
    return this.http.post<WebstoreAutoResult>(`${this.apiUrl}/prepare`, {});
  }

  batches(page = 1): Observable<WebstoreBatchHistory> {
    return this.http.get<WebstoreBatchHistory>(`${this.apiUrl}/batches`, { params: { page: String(page) } });
  }

  // ── Anti-duplication ──────────────────────────────────────────────────────

  /** Links a site product to a POS article; its SKU correction is then proposed. */
  link(wooId: number, productId: number): Observable<{ ok: boolean; summary: WebstoreSummary }> {
    return this.http.post<{ ok: boolean; summary: WebstoreSummary }>(`${this.apiUrl}/links`, { woo_id: wooId, product_id: productId });
  }

  unlink(wooId: number): Observable<{ ok: boolean; summary: WebstoreSummary }> {
    return this.http.delete<{ ok: boolean; summary: WebstoreSummary }>(`${this.apiUrl}/links/${wooId}`);
  }
}
