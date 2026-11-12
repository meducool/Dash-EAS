/** Where a due date falls relative to today's month — drives the colour code of upcoming transactions. */
export type DuePeriod = 'current' | 'next' | 'later';

export interface DuePeriodInfo {
  period: DuePeriod;
  title: string;
  /** e.g. "novembre 2026", or "janvier 2027 et après" for the open-ended bucket. */
  months: string;
}

/** Months since year 0 — makes "next month" a simple +1, December → January included. */
function monthIndex(year: number, month: number): number {
  return year * 12 + month;
}

/**
 * Classifies a due date (`YYYY-MM-DD…`) as current month, next month or any
 * later month. The year and month are read from the string itself: going
 * through `new Date(string)` would shift dates near midnight across months
 * depending on the browser's time zone.
 *
 * A date in a past month counts as `current` — it is due now.
 */
export function duePeriod(date: string | null | undefined, today: Date = new Date()): DuePeriod {
  const match = /^(\d{4})-(\d{2})/.exec(date ?? '');
  if (!match) return 'later';

  const diff = monthIndex(Number(match[1]), Number(match[2]) - 1) - monthIndex(today.getFullYear(), today.getMonth());
  if (diff <= 0) return 'current';
  return diff === 1 ? 'next' : 'later';
}

/** The three buckets with their French labels for the legend, relative to `today`. */
export function duePeriodLegend(today: Date = new Date()): DuePeriodInfo[] {
  const format = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' });
  const monthLabel = (offset: number) => format.format(new Date(today.getFullYear(), today.getMonth() + offset, 1));

  return [
    { period: 'current', title: 'Mois courant', months: monthLabel(0) },
    { period: 'next', title: 'Mois suivant', months: monthLabel(1) },
    { period: 'later', title: 'Autres mois futurs', months: `${monthLabel(2)} et après` },
  ];
}

/** `YYYY-MM` key of a due date, read from the string (no time-zone shift); null when unreadable. */
export function monthKey(date: string | null | undefined): string | null {
  const match = /^(\d{4})-(\d{2})/.exec(date ?? '');
  return match ? `${match[1]}-${match[2]}` : null;
}

/** Months between today's month and a `YYYY-MM` key: 0 = current month, 1 = next, -1 = last month. */
export function monthOffset(key: string, today: Date = new Date()): number {
  const [year, month] = key.split('-').map(Number);
  return monthIndex(year, month - 1) - monthIndex(today.getFullYear(), today.getMonth());
}

/** "Décembre 2026" for a `YYYY-MM` key. */
export function monthTitle(key: string): string {
  const [year, month] = key.split('-').map(Number);
  const label = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
  return label.charAt(0).toUpperCase() + label.slice(1);
}

/** `YYYY-MM` key of the month `offset` months after today's. */
export function monthKeyFromToday(offset: number, today: Date = new Date()): string {
  const d = new Date(today.getFullYear(), today.getMonth() + offset, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
