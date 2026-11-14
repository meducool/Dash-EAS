/** Boutique en ligne (WooCommerce) — step 1: connection and SKU matching report. */

export type WebstoreMatchStatus = 'matched' | 'site_only' | 'no_sku' | 'duplicate' | 'pos_only';

export interface WebstoreSummary {
  site_total: number;
  matched: number;
  site_only: number;
  no_sku: number;
  duplicate: number;
  pos_only: number;
  /** Variable products (SKU on the variations: not matched for now). */
  variable: number;
  /** POS articles probably already on the site under another SKU (never proposed in creation). */
  suspected_duplicates?: number;
  /** Linked site products whose SKU is not the POS reference (correction proposed). */
  sku_to_fix?: number;
  store_url: string | null;
  last_pull_at: string | null;
}

export interface WebstoreSettings {
  store_url: string | null;
  has_credentials: boolean;
  /** Last 4 characters of the saved consumer key (the keys are never sent back). */
  consumer_key_hint: string | null;
  /** Margin of the online shop (%), used to compute the proposed prices. */
  margin_percent: number | null;
  /** Status of the products created on the site: draft (checked on WordPress first) or publish. */
  create_status: 'draft' | 'publish';
  /** Batch prepared automatically every morning at 06:30 (never published without approval). */
  auto_prepare: boolean;
  last_pull_at: string | null;
  last_publish_at: string | null;
  last_pull_summary: WebstoreSummary | null;
}

export interface WebstoreSettingsPayload {
  store_url?: string | null;
  consumer_key?: string | null;
  consumer_secret?: string | null;
  clear_credentials?: boolean;
  margin_percent?: number | null;
  create_status?: 'draft' | 'publish';
  auto_prepare?: boolean;
}

export interface WebstoreWooProduct {
  id: number;
  sku: string | null;
  name: string;
  type: string | null;
  status: string | null;
  price: number | null;
  regular_price: number | null;
  sale_price: number | null;
  stock_status: string | null;
  stock_quantity: number | null;
  images_count: number;
  permalink: string | null;
}

export interface WebstorePosProduct {
  id: number;
  reference: string;
  type: string;
  is_active: boolean;
  label: string;
  stock?: number;
  images_count?: number;
  offers_count?: number;
  /** Cheapest supplier price (TTC) in the active price lists. */
  best_offer_ttc?: number | null;
}

export interface WebstoreReportRow {
  match_status: WebstoreMatchStatus;
  woo: WebstoreWooProduct | null;
  product: WebstorePosProduct | null;
  /** How the site product is linked to the article: same SKU, by hand, created by the POS. */
  link?: 'sku' | 'manual' | 'created' | null;
  /** Linked, but the site SKU is not the POS reference. */
  sku_mismatch?: boolean;
  /** POS article the site product looks like (unknown / empty SKU). */
  suggestion?: WebstorePosProduct | null;
  /** POS article probably already on the site under another SKU. */
  suspected_duplicate?: { woo_id: number; name: string; permalink: string | null } | null;
}

export interface WebstoreReport {
  data: WebstoreReportRow[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  summary: WebstoreSummary;
}

export const WEBSTORE_STATUS_LABELS: Record<WebstoreMatchStatus, string> = {
  matched: 'Correspondants',
  site_only: 'Inconnus du POS',
  no_sku: 'Sans SKU',
  duplicate: 'SKU en double',
  pos_only: 'Absents du site',
};

export const WEBSTORE_STATUS_HELP: Record<WebstoreMatchStatus, string> = {
  matched: 'Même SKU sur le site et dans le POS : ces produits pourront être mis à jour.',
  site_only: 'SKU du site introuvable dans le POS : associez-le à son article (suggestion 💡 ou recherche) — son SKU sera aligné sur la référence POS.',
  no_sku: 'Produits du site sans SKU : associez-les à leur article POS, le SKU leur sera donné à la publication.',
  duplicate: 'Le même SKU est utilisé par plusieurs produits du site : à dédoublonner sur le site.',
  pos_only: 'Pneus et pièces actifs du POS absents du site : ils pourront être proposés en création.',
};

export const WOO_STATUS_LABELS: Record<string, string> = {
  publish: 'Publié',
  draft: 'Brouillon',
  pending: 'En attente',
  private: 'Privé',
};

export const WOO_STOCK_LABELS: Record<string, string> = {
  instock: 'En stock',
  outofstock: 'Rupture',
  onbackorder: 'Sur commande',
};

// ── Step 2: proposals + approval ──────────────────────────────────────────

export type WebstoreChangeStatus = 'proposed' | 'approved' | 'rejected' | 'published' | 'failed';
export type WebstoreChangeAction = 'create' | 'update';
export type WebstoreAlert = 'price_jump' | 'below_cost' | 'no_photo' | 'site_draft' | 'manual_price';

export interface WebstoreBatch {
  id: number;
  /** open = the batch being decided; superseded = replaced by a newer one (read only). */
  status: 'open' | 'superseded';
  margin_percent: number;
  catalogue_read_at: string | null;
  created_at: string | null;
  created_by_name: string | null;
  counts: {
    create: number;
    update: number;
    unchanged: number;
    variable_skipped: number;
    no_price_skipped: number;
    /** Probably already on the site under another SKU: not created. */
    duplicate_skipped?: number;
    with_alerts: number;
  };
  statuses: Record<WebstoreChangeStatus, number>;
}

export interface WebstoreChangeValues {
  name?: string;
  status?: string | null;
  price?: number | null;
  stock_status?: string | null;
  stock_quantity?: number | null;
  images_count?: number;
  images?: { id: number; url: string }[];
  sku?: string;
  pos_stock?: number;
  pos_images?: number;
  best_offer_ttc?: number | null;
  purchase_cost?: number | null;
}

export interface WebstoreChange {
  id: number;
  action: WebstoreChangeAction;
  status: WebstoreChangeStatus;
  fields: ('price' | 'stock' | 'images' | 'sku')[];
  alerts: WebstoreAlert[];
  current: WebstoreChangeValues | null;
  proposed: WebstoreChangeValues;
  price_override: number | null;
  final_price: number | null;
  decided_by_name: string | null;
  decided_at: string | null;
  published_at: string | null;
  /** Failure reason, or a warning on a published line (e.g. photos not sent). */
  error: string | null;
  product: WebstorePosProduct | null;
  woo: { id: number; name: string; sku: string | null; permalink: string | null } | null;
}

export interface WebstoreChangesResponse {
  data: WebstoreChange[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  batch: WebstoreBatch | null;
}

export interface WebstoreChangesFilters {
  status?: WebstoreChangeStatus | '';
  action?: WebstoreChangeAction | '';
  alert?: WebstoreAlert | 'none' | 'any' | '';
  search?: string;
  page?: number;
  per_page?: number;
  /** An older batch (history), read only. */
  batch_id?: number | null;
}

export const WEBSTORE_ALERT_LABELS: Record<WebstoreAlert, string> = {
  price_jump: 'Variation > 10 %',
  below_cost: 'Sous le prix d\'achat',
  no_photo: 'Sans photo',
  site_draft: 'Brouillon sur le site',
  manual_price: 'Prix modifié à la main',
};

export const WEBSTORE_CHANGE_STATUS_LABELS: Record<WebstoreChangeStatus, string> = {
  proposed: 'À décider',
  approved: 'Approuvée',
  rejected: 'Rejetée',
  published: 'Publiée',
  failed: 'Échec',
};

export interface WebstorePublishResult {
  published: number;
  failed: number;
  /** Published lines with a warning (photos the site could not download). */
  warnings: number;
  /** Set when the site refused a whole request: the lines not sent stay approved. */
  stopped: string | null;
  batch: WebstoreBatch | null;
}

// ── Step 4: morning preparation, status, history ────────────────────────

export interface WebstoreAutoResult {
  status: 'ok' | 'skipped' | 'error' | 'off';
  message: string;
  counts?: { batch_id: number; update: number; create: number; with_alerts: number };
}

export interface WebstoreStatus {
  batch: WebstoreBatch | null;
  auto: { enabled: boolean; last_run_at: string | null; last_result: WebstoreAutoResult | null };
  last_publish_at: string | null;
}

export interface WebstoreBatchSummary {
  id: number;
  status: 'open' | 'superseded';
  margin_percent: number;
  created_at: string | null;
  created_by_name: string | null;
  counts: WebstoreBatch['counts'] | null;
  statuses: Partial<Record<WebstoreChangeStatus | 'superseded', number>>;
}

export interface WebstoreBatchHistory {
  data: WebstoreBatchSummary[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}
