import { monthKey, monthKeyFromToday, monthOffset, monthTitle } from './due-period';

/**
 * Upcoming transactions (cheques / bills of exchange not due yet) grouped by
 * due month — shared by Cash Flow and the account detail page.
 */

/** Colour family of a month: current, next, the one after, any later one — or already past. */
export type PendingTone = 'overdue' | 'current' | 'next' | 'after' | 'later';

/** What the grouping needs from a transaction. */
export interface PendingItem {
  date: string;
  amount: number | string;
  type: string;
}

export interface PendingMonth<T extends PendingItem = PendingItem> {
  key: string;
  title: string;
  /** "Mois courant", "Mois prochain", "Mois suivant", "En retard" — null for later months. */
  tag: string | null;
  tone: PendingTone;
  items: T[];
  /** Signed: income adds, expense subtracts. */
  total: number;
}

const MONTH_TAGS: Record<PendingTone, string | null> = {
  overdue: 'En retard',
  current: 'Mois courant',
  next: 'Mois prochain',
  after: 'Mois suivant',
  later: null,
};

function toneOf(offset: number): PendingTone {
  if (offset < 0) return 'overdue';
  return (['current', 'next', 'after'] as const)[offset] ?? 'later';
}

/** Income adds, expense subtracts. */
export function signedAmount(t: PendingItem): number {
  const amount = Number(t.amount) || 0;
  return t.type === 'expense' ? -amount : amount;
}

/** Groups by due month, latest month first, latest date first inside a month. */
export function groupPendingByMonth<T extends PendingItem>(items: readonly T[], today: Date = new Date()): PendingMonth<T>[] {
  const groups = new Map<string, T[]>();
  for (const t of items) {
    const key = monthKey(t.date) ?? '0000-00';
    groups.set(key, [...(groups.get(key) ?? []), t]);
  }

  return [...groups.entries()]
    .sort(([a], [b]) => b.localeCompare(a))
    .map(([key, list]) => {
      const tone = toneOf(monthOffset(key, today));
      return {
        key,
        title: monthTitle(key),
        tag: MONTH_TAGS[tone],
        tone,
        items: [...list].sort((a, b) => b.date.localeCompare(a.date)),
        total: list.reduce((sum, t) => sum + signedAmount(t), 0),
      };
    });
}

/** The three cards: current month, next month, the one after — shown even when empty. */
export function pendingMonthCards<T extends PendingItem>(months: readonly PendingMonth<T>[], today: Date = new Date()): PendingMonth<T>[] {
  return [0, 1, 2].map((offset) => {
    const key = monthKeyFromToday(offset, today);
    const tone = toneOf(offset);
    return months.find((m) => m.key === key) ?? { key, title: monthTitle(key), tag: MONTH_TAGS[tone], tone, items: [], total: 0 };
  });
}

/** Number of upcoming transactions and their signed total. */
export function pendingTotals(items: readonly PendingItem[]): { count: number; total: number } {
  return { count: items.length, total: items.reduce((sum, t) => sum + signedAmount(t), 0) };
}
