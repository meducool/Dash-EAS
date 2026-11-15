import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { environment } from '../../../../environments/environment';
import { PaginatedResponse, Product, ProductFilters, ProductPayload } from '../models/product.model';

/** A tyre already in the catalogue with the same characteristics as the one being created. */
export interface ExistingTyre {
  id: number;
  reference: string;
  profile: string | null;
  is_active: boolean;
  stock: number;
}

@Injectable({
  providedIn: 'root'
})
export class ProductService {
  private apiUrl = `${environment.apiUrl}/products`;

  constructor(private http: HttpClient) {}

  getProducts(filters: Record<string, string> = {}): Observable<PaginatedResponse<Product>> {
    const params = new HttpParams({ fromObject: filters });
    return this.http.get<PaginatedResponse<Product>>(this.apiUrl, { params });
  }

  getProduct(id: number): Observable<Product> {
    return this.http.get<Product>(`${this.apiUrl}/${id}`);
  }

  /** The reference (SKU) a new product with these characteristics would get. */
  /** Reference the new product would get + tyres already in the catalogue with the same characteristics. */
  previewReference(params: Record<string, string>): Observable<{ reference: string; existing?: ExistingTyre[] }> {
    return this.http.get<{ reference: string; existing?: ExistingTyre[] }>(`${environment.apiUrl}/products-reference-preview`, { params });
  }

  getFilters(): Observable<ProductFilters> {
    return this.http.get<ProductFilters>(`${this.apiUrl}-filters`);
  }

  createProduct(payload: ProductPayload): Observable<Product> {
    return this.http.post<Product>(this.apiUrl, payload);
  }

  updateProduct(id: number, payload: ProductPayload): Observable<Product> {
    return this.http.put<Product>(`${this.apiUrl}/${id}`, payload);
  }

  deleteProduct(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`);
  }

  toggleActive(id: number): Observable<Product> {
    return this.http.patch<Product>(`${this.apiUrl}/${id}/toggle-active`, {});
  }
}