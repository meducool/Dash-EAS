/**
 * Amount written in French words, as printed at the bottom of a sale / purchase
 * document: 3000 → « Trois mille dirhams », 1250.5 → « Mille deux cent cinquante
 * dirhams et cinquante centimes ».
 */

const UNITS = [
  'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
  'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
  'dix-sept', 'dix-huit', 'dix-neuf',
];
const TENS = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante'];

/** 0–99. `final` = last word of the number (quatre-vingts keeps its s). */
function belowHundred(n: number, final: boolean): string {
  if (n < 20) return UNITS[n];
  if (n < 70) {
    const t = Math.floor(n / 10);
    const u = n % 10;
    if (u === 0) return TENS[t];
    return u === 1 ? `${TENS[t]} et un` : `${TENS[t]}-${UNITS[u]}`;
  }
  if (n < 80) return n === 71 ? 'soixante et onze' : `soixante-${UNITS[n - 60]}`;
  if (n === 80) return final ? 'quatre-vingts' : 'quatre-vingt';
  return `quatre-vingt-${UNITS[n - 80]}`;
}

/** 0–999. */
function belowThousand(n: number, final: boolean): string {
  const h = Math.floor(n / 100);
  const rest = n % 100;
  const parts: string[] = [];
  if (h > 0) {
    if (h === 1) parts.push('cent');
    else parts.push(rest === 0 && final ? `${UNITS[h]} cents` : `${UNITS[h]} cent`);
  }
  if (rest > 0) parts.push(belowHundred(rest, final));
  return parts.join(' ');
}

/** Whole number in French words (0 ≤ n < 10^12). */
export function integerInWords(n: number): string {
  n = Math.floor(Math.abs(n));
  if (n === 0) return 'zéro';

  const billions = Math.floor(n / 1e9);
  const millions = Math.floor((n % 1e9) / 1e6);
  const thousands = Math.floor((n % 1e6) / 1e3);
  const rest = n % 1e3;
  const parts: string[] = [];

  if (billions) parts.push(`${belowThousand(billions, true)} milliard${billions > 1 ? 's' : ''}`);
  if (millions) parts.push(`${belowThousand(millions, true)} million${millions > 1 ? 's' : ''}`);
  if (thousands) parts.push(thousands === 1 ? 'mille' : `${belowThousand(thousands, false)} mille`);
  if (rest) parts.push(belowThousand(rest, true));

  return parts.join(' ');
}

/** « Trois mille dirhams et cinquante centimes ». */
export function amountInWords(amount: number | string | null | undefined): string {
  const value = Math.abs(Number(amount) || 0);
  const cents = Math.round(value * 100);
  const whole = Math.floor(cents / 100);
  const centimes = cents % 100;

  let text = `${integerInWords(whole)} dirham${whole > 1 ? 's' : ''}`;
  // « un million de dirhams », « deux milliards de dirhams »
  if (whole > 0 && whole % 1e6 === 0) text = text.replace(/ dirhams$/, ' de dirhams');
  if (centimes > 0) text += ` et ${integerInWords(centimes)} centime${centimes > 1 ? 's' : ''}`;

  return text.charAt(0).toUpperCase() + text.slice(1);
}
