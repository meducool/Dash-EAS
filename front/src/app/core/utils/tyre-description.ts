/**
 * Reads a tyre description as typed by the shop — e.g.
 * "205/80R16 104H XL CrossContact ATR" or "CONTINENTAL 225/45 ZR18 95Y SportContact 7 MO"
 * — and extracts what the product form needs: dimensions, load / speed
 * indexes, XL, run flat, season, OEM markings, brand and profile.
 *
 * Pure function, no I/O: unknown parts are simply left out.
 */
export interface ParsedTyre {
  width?: number;
  height?: number;
  diameter?: number;
  loadIndex?: string;
  speedIndex?: string;
  reinforced?: boolean;
  runflat?: boolean;
  season?: 'summer' | 'winter' | 'all_season';
  /** OEM / homologation markings found, e.g. "MO, *". */
  marking?: string;
  /** Id of the brand whose name appears in the text. */
  brandId?: number;
  /** What remains once everything above is taken out — the tread pattern name. */
  profile?: string;
}

/** 205/55R16, 205/55 ZR16, 225/45ZR18, 195/70R15C, 235/65R17 C, LT265/70R17, 31x10.50R15, 205/55-16 */
const DIMENSION = /\b(?:LT|P)?\s*(\d{2,3}(?:[.,]\d{1,2})?)\s*[/xX]\s*(\d{1,3}(?:[.,]\d{1,2})?)\s*(?:Z?R|-)\s*F?\s*(\d{2}(?:[.,]\d)?)\s*C?\b/i;
/** 104H, 91V, 112/110R, 95 Y, 97W — right after the dimension. */
const LOAD_SPEED = /^\s*(\d{2,3})(?:\/\d{2,3})?\s*([A-HJ-NP-WYZ]|ZR)\b/i;

const REINFORCED = /\b(XL|EXTRA\s*LOAD|REINF(?:ORCED)?|RENFORC[EÉ])\b/i;
const RUNFLAT = /\b(RFT|ROF|RUN\s*-?\s*FLAT|ZP|SSR|DSST|EMT|HRS)\b/i;
const SEASONS: { re: RegExp; season: ParsedTyre['season'] }[] = [
  { re: /\b(ALL\s*-?\s*SEASONS?|4\s*-?\s*SAISONS?|QUATRE\s*SAISONS|4S)\b/i, season: 'all_season' },
  { re: /\b(WINTER|HIVER|SNOW|NEIGE|3PMSF)\b/i, season: 'winter' },
  { re: /\b(SUMMER|[EÉ]T[EÉ])\b/i, season: 'summer' },
];
/** Homologation markings: MO, MOE, MO1, AO, AOE, RO1, N0…N9, *, PNCS, LR, J, VOL, T0, ELT, NF0… */
const MARKINGS = /(?<![\w*])(MOE?S?|MO1|AOE?|RO[12]|N[0-9]|NF0|NC0|PNCS|SEAL(?:INSIDE)?|ELT|LR|VOL|J|T[0-2]|TO|K[1-3]|\*)(?![\w*])/gi;
const NOISE_WORDS = /\b(PNEU|PNEUS|TYRE|TIRE|TL|TT|M\+S|M\/S|DOT\s*\d{4})\b/gi;

function toNumber(value: string): number {
  return Number(value.replace(',', '.'));
}

function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function parseTyreDescription(text: string | null | undefined, brands: { id: number; name: string }[] = []): ParsedTyre {
  let rest = ` ${(text ?? '').replace(/\s+/g, ' ').trim()} `;
  const result: ParsedTyre = {};
  if (!rest.trim()) return result;

  const take = (re: RegExp) => {
    rest = rest.replace(re, ' ');
  };

  // Dimension, then the load / speed indexes that follow it.
  const dim = DIMENSION.exec(rest);
  if (dim) {
    result.width = toNumber(dim[1]);
    result.height = toNumber(dim[2]);
    result.diameter = toNumber(dim[3]);
    const after = rest.slice(dim.index + dim[0].length);
    const ls = LOAD_SPEED.exec(after);
    rest = rest.slice(0, dim.index) + ' ' + (ls ? after.slice(ls[0].length) : after);
    if (ls) {
      result.loadIndex = ls[1];
      result.speedIndex = ls[2].toUpperCase() === 'ZR' ? 'W' : ls[2].toUpperCase();
    }
  }

  if (REINFORCED.test(rest)) {
    result.reinforced = true;
    take(new RegExp(REINFORCED.source, 'gi'));
  }
  if (RUNFLAT.test(rest)) {
    result.runflat = true;
    take(new RegExp(RUNFLAT.source, 'gi'));
  }
  for (const { re, season } of SEASONS) {
    if (re.test(rest)) {
      result.season = season;
      take(new RegExp(re.source, 'gi'));
      break;
    }
  }

  // Brand: the longest brand name found as a whole word ("BF GOODRICH" before "GOODRICH").
  const brand = [...brands]
    .filter((b) => b.name?.trim())
    .sort((a, b) => b.name.length - a.name.length)
    .find((b) => new RegExp(`(^|[^\\p{L}\\d])${escapeRegExp(b.name.trim())}(?=$|[^\\p{L}\\d])`, 'iu').test(rest));
  if (brand) {
    result.brandId = brand.id;
    take(new RegExp(`(^|[^\\p{L}\\d])${escapeRegExp(brand.name.trim())}(?=$|[^\\p{L}\\d])`, 'giu'));
  }

  take(NOISE_WORDS);

  const markings: string[] = [];
  rest = rest.replace(MARKINGS, (m) => {
    markings.push(m.toUpperCase());
    return ' ';
  });
  if (markings.length) result.marking = [...new Set(markings)].join(', ');

  const profile = rest.replace(/\s+/g, ' ').replace(/^[\s\-–—|,;:]+|[\s\-–—|,;:]+$/g, '').trim();
  if (profile) result.profile = profile;

  return result;
}
