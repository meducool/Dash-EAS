import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { Product, ProductPayload, ProductFilters, PaginatedResponse } from '../models/product.model';
import { environment } from '../../../environments/environment';

export interface ProductPurchasePrices {
  /** Highest purchase price found (null = never bought). */
  price: number | null;
  /** Several different prices: the highest is proposed, to check by hand. */
  multiple: boolean;
  /** 'stock' = purchases of that lot, 'product' = every purchase of the product. */
  scope: 'stock' | 'product' | null;
  prices: { unit_price: number; purchases_count: number; quantity: number; last_date: string; last_purchase_id: number; suppliers: string[] }[];
}

export interface ProductPurchaseHistoryLine {
  id: number;
  purchase_id: number;
  date: string;
  status: string;
  cancelled: boolean;
  bl_number: string | null;
  invoice_number: string | null;
  supplier: { id: number; name: string } | null;
  depot: string | null;
  quantity: number;
  unit_price: number;
}

export interface ProductPurchaseHistory {
  data: ProductPurchaseHistoryLine[];
  /** Cancelled purchases left out. average_price is weighted by quantity. */
  summary: {
    purchases_count: number;
    quantity: number;
    last_price: number | null;
    min_price: number | null;
    max_price: number | null;
    average_price: number | null;
    suppliers_count: number;
  };
}

export interface ProductImage {
  id: number;
  product_id: number;
  url: string;
  original_name: string | null;
  size: number | null;
  /** 0 = main photo. */
  position: number;
  created_at: string;
}

export interface ProductImageList {
  data: ProductImage[];
  /** Max photos per article. */
  max: number;
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

  /** Purchase prices of a product from its purchases (highest first) — "PU Achat" of the sale form. */
  getPurchasePrices(id: number, stockId?: number | null): Observable<ProductPurchasePrices> {
    const params: Record<string, string> = stockId ? { stock_id: String(stockId) } : {};
    return this.http.get<ProductPurchasePrices>(`${this.apiUrl}/${id}/purchase-prices`, { params });
  }

  /** Every purchase of the product (most recent first) with supplier and unit price. */
  getPurchaseHistory(id: number): Observable<ProductPurchaseHistory> {
    return this.http.get<ProductPurchaseHistory>(`${this.apiUrl}/${id}/purchase-history`);
  }

  /** Photos of the article, main photo first. */
  getImages(id: number): Observable<ProductImageList> {
    return this.http.get<ProductImageList>(`${this.apiUrl}/${id}/images`);
  }

  /** Adds photos after the existing ones; answers the whole new list. */
  uploadImages(id: number, files: File[]): Observable<ProductImageList> {
    const form = new FormData();
    files.forEach((file) => form.append('images[]', file, file.name));
    return this.http.post<ProductImageList>(`${this.apiUrl}/${id}/images`, form);
  }

  /** New order of the photos (first = main photo). */
  reorderImages(id: number, ids: number[]): Observable<ProductImageList> {
    return this.http.put<ProductImageList>(`${this.apiUrl}/${id}/images/order`, { ids });
  }

  deleteImage(imageId: number): Observable<ProductImageList> {
    return this.http.delete<ProductImageList>(`${environment.apiUrl}/product-images/${imageId}`);
  }

  getProduct(id: number): Observable<Product> {
    return this.http.get<Product>(`${this.apiUrl}/${id}`);
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
