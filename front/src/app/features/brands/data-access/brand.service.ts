import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Brand, BrandPayload, BrandProfile, PaginatedResponse, ProfileDuplicateGroup } from '../models/brand.model';
import { environment } from '../../../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class BrandService {
  private apiUrl = `${environment.apiUrl}/brands`;

  constructor(private http: HttpClient) {}

  getBrands(filters: Record<string, string> = {}): Observable<PaginatedResponse<Brand>> {
    const params = new HttpParams({ fromObject: filters });
    return this.http.get<PaginatedResponse<Brand>>(this.apiUrl, { params });
  }

  getAllBrands(): Observable<Brand[]> {
    return this.http.get<Brand[]>(this.apiUrl, { params: { all: '1' } });
  }

  createBrand(payload: BrandPayload, logo?: File): Observable<Brand> {
    const formData = this.buildFormData(payload, logo);
    return this.http.post<Brand>(this.apiUrl, formData);
  }

  updateBrand(id: number, payload: BrandPayload, logo?: File): Observable<Brand> {
    const formData = this.buildFormData(payload, logo);
    formData.append('_method', 'PUT');
    return this.http.post<Brand>(`${this.apiUrl}/${id}`, formData);
  }

  private buildFormData(payload: BrandPayload, logo?: File): FormData {
    const formData = new FormData();
    formData.append('name', payload.name);
    formData.append('code', (payload.code ?? '').trim());
    formData.append('is_active', payload.is_active ? '1' : '0');
    if (logo) {
      formData.append('logo', logo);
    }
    return formData;
  }

  deleteBrand(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`);
  }

  // ── Profils de pneus d'une marque ──
  getProfiles(brandId: number): Observable<BrandProfile[]> {
    return this.http.get<BrandProfile[]>(`${this.apiUrl}/${brandId}/profiles`);
  }

  createProfile(brandId: number, name: string, code?: string | null): Observable<BrandProfile> {
    return this.http.post<BrandProfile>(`${this.apiUrl}/${brandId}/profiles`, { name, code: code?.trim() || null });
  }

  /** Also renames the products of the brand that carry the old name. */
  renameProfile(profileId: number, name: string, code?: string | null): Observable<BrandProfile> {
    return this.http.put<BrandProfile>(`${environment.apiUrl}/brand-profiles/${profileId}`, { name, code: code?.trim() || null });
  }

  /** Profiles written several ways under the same brand (every brand). */
  getProfileDuplicates(): Observable<{ data: ProfileDuplicateGroup[] }> {
    return this.http.get<{ data: ProfileDuplicateGroup[] }>(`${environment.apiUrl}/brand-profiles-duplicates`);
  }

  /** Merges a profile into another of the same brand: its products take the kept name. */
  mergeProfile(profileId: number, intoId: number): Observable<BrandProfile> {
    return this.http.post<BrandProfile>(`${environment.apiUrl}/brand-profiles/${profileId}/merge`, { into_id: intoId });
  }

  deleteProfile(profileId: number): Observable<void> {
    return this.http.delete<void>(`${environment.apiUrl}/brand-profiles/${profileId}`);
  }

  toggleActive(id: number): Observable<Brand> {
    return this.http.patch<Brand>(`${this.apiUrl}/${id}/toggle-active`, {});
  }
}