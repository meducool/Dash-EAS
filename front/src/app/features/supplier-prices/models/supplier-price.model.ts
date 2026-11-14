/** Tarifs fournisseurs — price lists read from Excel files or online links. */

export type PriceListField = 'designation' | 'reference' | 'brand' | 'profile' | 'dimension' | 'indexes' | 'runflat' | 'price' | 'availability';

export const PRICE_LIST_FIELD_LABELS: Record<PriceListField, string> = {
  designation: 'Désignation',
  reference: 'Référence fournisseur',
  brand: 'Marque',
  profile: 'Profil / modèle',
  dimension: 'Dimension',
  indexes: 'IC/IV (charge / vitesse)',
  runflat: 'Runflat (Oui / Non)',
  price: 'Prix TTC (fournisseur)',
  availability: 'Stock / disponibilité',
};

export type ColumnMapping = Partial<Record<PriceListField, string>>;

export interface SupplierPriceFile {
  id: number;
  supplier_id: number;
  supplier?: { id: number; name: string } | null;
  name: string;
  source_type: 'upload' | 'url';
  source_url: string | null;
  auto_refresh: boolean;
  original_filename: string | null;
  column_mapping: ColumnMapping | null;
  /** Margin of this price list (%), null = the default margin. */
  margin_percent: number | null;
  /** Column letter → header text, as read in the file. */
  headers: Record<string, string> | null;
  rows_count: number;
  identified_count: number;
  is_active: boolean;
  last_imported_at: string | null;
  last_error: string | null;
}

export interface SupplierPriceOffer {
  id: number;
  price: number;
  /** Computed: Prix TTC × (1 + margin_percent / 100) — the file's margin, else the default one. */
  sale_price: number;
  margin: number;
  margin_percent: number;
  is_best: boolean;
  designation: string;
  supplier_reference: string | null;
  availability: string | null;
  supplier: { id: number; name: string } | null;
  file: { id: number; name: string; last_imported_at: string | null } | null;
}

/** One tyre (same generated reference) and every offer found for it. */
export interface SupplierPriceGroup {
  key: string;
  match_key: string | null;
  brand_id: number | null;
  brand: string | null;
  profile: string | null;
  width: number | null;
  height: number | null;
  diameter: number | null;
  dimension: string | null;
  load_index: string | null;
  speed_index: string | null;
  runflat: boolean;
  reinforced: boolean;
  season: string | null;
  identified: boolean;
  product: { id: number; reference: string; stock_quantity?: number } | null;
  best_price: number;
  max_price: number;
  spread: number;
  offers_count: number;
  suppliers_count: number;
  offers: SupplierPriceOffer[];
}

export interface SupplierPriceSearchResponse {
  data: SupplierPriceGroup[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  lines_count: number;
  truncated: boolean;
  default_margin: number;
}

export interface SupplierPriceSettings {
  default_margin: number;
  can_edit_margin: boolean;
}

/** Values present in the active price lists, for the comparison filters. */
export interface SupplierPriceFilterOptions {
  speed_indexes: { value: string; count: number; kmh: number | null }[];
  load_indexes: { value: string; count: number }[];
  runflat_count: number;
  reinforced_count: number;
}

export interface SupplierPriceBrand {
  brand_id: number | null;
  name: string | null;
  identified: boolean;
  items_count: number;
  suppliers_count: number;
  min_price: number;
  max_price: number;
}

export interface SupplierPriceBrandsResponse {
  data: SupplierPriceBrand[];
  summary: { files_count: number; suppliers_count: number; items_count: number; brands_count: number };
}

export interface SupplierPriceFilePayload {
  supplier_id?: number;
  name?: string;
  source_type?: 'upload' | 'url';
  source_url?: string | null;
  auto_refresh?: boolean;
  is_active?: boolean;
  column_mapping?: ColumnMapping | null;
  margin_percent?: number | null;
}

export interface CreatedFromPriceLine {
  product: { id: number; reference: string };
  created: boolean;
  supplier_id: number;
  price: number;
}
