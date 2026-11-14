import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../../environments/environment';
import {
  CreatedFromPriceLine,
  SupplierPriceBrandsResponse,
  SupplierPriceFile,
  SupplierPriceFilterOptions,
  SupplierPriceFilePayload,
  SupplierPriceSearchResponse,
  SupplierPriceSettings,
} from '../models/supplier-price.model';

@Injectable({ providedIn: 'root' })
export class SupplierPriceService {
  private http = inject(HttpClient);
  private apiUrl = environment.apiUrl;

  search(filters: Record<string, string>): Observable<SupplierPriceSearchResponse> {
    const clean = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v != null));
    return this.http.get<SupplierPriceSearchResponse>(`${this.apiUrl}/supplier-prices`, { params: new HttpParams({ fromObject: clean }) });
  }

  settings(): Observable<SupplierPriceSettings> {
    return this.http.get<SupplierPriceSettings>(`${this.apiUrl}/supplier-prices-settings`);
  }

  /** Default margin (%) — Administrator only. */
  updateSettings(defaultMargin: number): Observable<SupplierPriceSettings> {
    return this.http.put<SupplierPriceSettings>(`${this.apiUrl}/supplier-prices-settings`, { default_margin: defaultMargin });
  }

  filterOptions(): Observable<SupplierPriceFilterOptions> {
    return this.http.get<SupplierPriceFilterOptions>(`${this.apiUrl}/supplier-prices-filters`);
  }

  brands(): Observable<SupplierPriceBrandsResponse> {
    return this.http.get<SupplierPriceBrandsResponse>(`${this.apiUrl}/supplier-prices-brands`);
  }

  files(): Observable<{ data: SupplierPriceFile[] }> {
    return this.http.get<{ data: SupplierPriceFile[] }>(`${this.apiUrl}/supplier-price-files`);
  }

  /** Standard template (all brands) to fill in Google Sheets or Excel. */
  downloadTemplate(): Observable<Blob> {
    return this.http.get(`${this.apiUrl}/supplier-price-files/template`, { responseType: 'blob' });
  }

  createFile(payload: SupplierPriceFilePayload, file?: File | null): Observable<SupplierPriceFile> {
    return this.http.post<SupplierPriceFile>(`${this.apiUrl}/supplier-price-files`, this.formData(payload, file));
  }

  /** POST (multipart): a replacement file may be sent. */
  updateFile(id: number, payload: SupplierPriceFilePayload, file?: File | null): Observable<SupplierPriceFile> {
    return this.http.post<SupplierPriceFile>(`${this.apiUrl}/supplier-price-files/${id}`, this.formData(payload, file));
  }

  refreshFile(id: number): Observable<SupplierPriceFile> {
    return this.http.post<SupplierPriceFile>(`${this.apiUrl}/supplier-price-files/${id}/refresh`, {});
  }

  deleteFile(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/supplier-price-files/${id}`);
  }

  /** Catalogue product for a price line (created if missing), to start a purchase with. */
  productFor(itemId: number): Observable<CreatedFromPriceLine> {
    return this.http.post<CreatedFromPriceLine>(`${this.apiUrl}/supplier-prices/${itemId}/product`, {});
  }

  private formData(payload: SupplierPriceFilePayload, file?: File | null): FormData {
    const data = new FormData();
    for (const [key, value] of Object.entries(payload)) {
      // margin_percent: null is sent empty, meaning "back to the default margin".
      if (key === 'margin_percent' && value === null) {
        data.append(key, '');
        continue;
      }
      if (value === undefined || value === null || key === 'column_mapping') continue;
      data.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    }
    for (const [field, letter] of Object.entries(payload.column_mapping ?? {})) {
      if (letter) data.append(`column_mapping[${field}]`, letter);
    }
    if (file) data.append('file', file);
    return data;
  }
}
