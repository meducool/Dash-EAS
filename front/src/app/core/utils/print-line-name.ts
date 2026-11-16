/**
 * How an article is named on a printed document (bon de vente / d'achat /
 * fiche d'intervention).
 *
 * A tyre with dimensions is named like on the online shop (twin of
 * WebstoreProposalService::siteName() on the back end):
 *   « Pneu Goodyear EfficientGrip Performance 185/60 R15 84H »
 * and its POS reference goes on the small line below. Any other article
 * (part, service) keeps its previous naming.
 */

export interface PrintLineName {
  label: string;
  reference?: string;
  details?: string;
}

/**
 * A brand or profile written in capitals, written like the shop's model:
 * "MATADOR" → "Matador", "CINTURATO P7 BLUE" → "Cinturato P7 Blue", "BF GOODRICH" → "BF Goodrich".
 * Words of ≤ 3 letters and words with digits stay as written; mixed case is kept.
 */
export function labelCase(text: string | null | undefined): string {
  const value = (text ?? '').trim();
  if (!value || value !== value.toUpperCase()) return value;
  return value
    .split(/\s+/)
    .map((word) => (word.length <= 3 || /\d/.test(word) ? word : titleWord(word)))
    .join(' ');
}

/** "ALL-TERRAIN" → "All-Terrain". */
function titleWord(word: string): string {
  return word
    .toLowerCase()
    .replace(/(^|[-'/])(\p{L})/gu, (_, sep: string, letter: string) => sep + letter.toUpperCase());
}

/** 205.00 → "205", 10.5 → "10.5". */
function num(value: unknown): string {
  const n = Number(value);
  return Number.isFinite(n) ? String(Number(n.toFixed(2))) : String(value ?? '');
}

/** « Pneu Matador Hectorra 5 205/50 R17 93Y », or null when it is not a tyre with dimensions. */
export function tyreSiteName(product: any): string | null {
  const t = product?.tyre;
  if (product?.type !== 'tyre' || !t?.tire_width || !t?.tire_diameter) return null;

  const dimension = `${num(t.tire_width)}${t.tire_height ? '/' + num(t.tire_height) : ''} R${num(t.tire_diameter)}`;
  const indexes = `${t.tire_load_index ?? ''}${t.tire_speed_index ?? ''}`.trim();

  return ['Pneu', labelCase(product.brand?.name), labelCase(product.profile), dimension, indexes]
    .filter(Boolean)
    .join(' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Name + small line of a document line. */
export function printLineName(product: any, fallback: string): PrintLineName {
  const siteName = tyreSiteName(product);
  if (siteName) {
    return {
      label: siteName,
      reference: product?.reference || undefined,
      details: product?.tyre?.tire_marking || undefined,
    };
  }

  if (product?.type === 'tyre') {
    // Tyre without dimensions: brand + profile, as before.
    const name = [product.brand?.name, product.profile].filter(Boolean).join(' ');
    return { label: name || product?.reference || fallback, reference: product?.reference || undefined };
  }

  return { label: product?.reference || fallback, reference: product?.profile || undefined };
}
