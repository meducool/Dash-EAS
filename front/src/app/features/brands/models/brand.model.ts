export interface Brand {
  id: number;
  name: string;
  /** Short code the product references start with, e.g. GITI → GT. */
  code?: string | null;
  logo: string | null;
  is_active: boolean;
  /** Number of tyre profiles managed under this brand. */
  profiles_count?: number;
  created_at?: string;
  updated_at?: string;
}

/** A tyre profile of a brand (e.g. MICHELIN → PRIMACY 4). */
export interface BrandProfile {
  id: number;
  brand_id: number;
  name: string;
  /** Short code ending the product references, e.g. SYNERGY → SYN. */
  code?: string | null;
  /** Products of the brand carrying this profile. */
  products_count: number;
}

export interface BrandPayload {
  name: string;
  code?: string | null;
  logo?: string | null;
  is_active?: boolean;
}

export interface PaginatedResponse<T> {
  current_page: number;
  data: T[];
  last_page: number;
  per_page: number;
  total: number;
}

/** Profiles of one brand written several ways ("EcoContact 6" / "ECOCONTACT6"), most used first. */
export interface ProfileDuplicateGroup {
  brand_id: number;
  brand: string;
  profiles: BrandProfile[];
}
