import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, shareReplay, tap } from 'rxjs';
import { environment } from '../../../environments/environment';

/** A depot (warehouse) of the reference list — Paramètres › Dépôts. */
export interface Depot {
  id: number;
  name: string;
  /** Stock lots stored in this depot. */
  stocks_count: number;
}

/**
 * Depots reference list. The list is cached for the session (every "Dépôt"
 * picker shares it) and refreshed after any change made through this service.
 */
@Injectable({ providedIn: 'root' })
export class DepotService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = `${environment.apiUrl}/depots`;
  private cache$: Observable<Depot[]> | null = null;

  getDepots(): Observable<Depot[]> {
    if (!this.cache$) {
      this.cache$ = this.http.get<Depot[]>(this.apiUrl).pipe(shareReplay(1));
      // A failed load must not stay cached.
      this.cache$.subscribe({ error: () => (this.cache$ = null) });
    }
    return this.cache$;
  }

  refresh(): Observable<Depot[]> {
    this.cache$ = null;
    return this.getDepots();
  }

  createDepot(name: string): Observable<Depot> {
    return this.http.post<Depot>(this.apiUrl, { name }).pipe(tap(() => (this.cache$ = null)));
  }

  /** Also renames every stock lot stored in it. */
  renameDepot(id: number, name: string): Observable<Depot> {
    return this.http.put<Depot>(`${this.apiUrl}/${id}`, { name }).pipe(tap(() => (this.cache$ = null)));
  }

  deleteDepot(id: number): Observable<void> {
    return this.http.delete<void>(`${this.apiUrl}/${id}`).pipe(tap(() => (this.cache$ = null)));
  }
}
