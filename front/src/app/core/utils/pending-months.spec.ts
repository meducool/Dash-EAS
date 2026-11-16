import { groupPendingByMonth, pendingMonthCards, pendingTotals } from './pending-months';

const today = new Date(2026, 10, 15); // 15 Nov 2026

const items = [
  { id: 1, date: '2026-12-25', amount: 12600, type: 'expense' },
  { id: 2, date: '2027-01-10', amount: '24200', type: 'expense' },
  { id: 3, date: '2026-12-17', amount: 19440, type: 'expense' },
  { id: 4, date: '2026-11-30', amount: 1000, type: 'income' },
  { id: 5, date: '2027-03-02', amount: 500, type: 'expense' },
];

describe('groupPendingByMonth', () => {
  it('groups by due month, latest month and latest date first, with signed totals', () => {
    const months = groupPendingByMonth(items, today);
    expect(months.map((m) => m.key)).toEqual(['2027-03', '2027-01', '2026-12', '2026-11']);
    const dec = months.find((m) => m.key === '2026-12')!;
    expect(dec.items.map((t) => t.id)).toEqual([1, 3]);
    expect(dec.total).toBe(-32040);
    expect(dec.title).toBe('Décembre 2026');
  });

  it('tags the months relative to today', () => {
    const tones = Object.fromEntries(groupPendingByMonth(items, today).map((m) => [m.key, [m.tone, m.tag]]));
    expect(tones['2026-11']).toEqual(['current', 'Mois courant']);
    expect(tones['2026-12']).toEqual(['next', 'Mois prochain']);
    expect(tones['2027-01']).toEqual(['after', 'Mois suivant']);
    expect(tones['2027-03']).toEqual(['later', null]);
    expect(groupPendingByMonth([{ date: '2026-10-02', amount: 1, type: 'income' }], today)[0].tone).toBe('overdue');
  });
});

describe('pendingMonthCards', () => {
  it('always gives the current month and the two next, empty ones included', () => {
    const cards = pendingMonthCards(groupPendingByMonth([items[1]], today), today);
    expect(cards.map((c) => [c.key, c.items.length])).toEqual([['2026-11', 0], ['2026-12', 0], ['2027-01', 1]]);
  });
});

describe('pendingTotals', () => {
  it('counts and sums with signs', () => {
    expect(pendingTotals(items)).toEqual({ count: 5, total: 1000 - 12600 - 24200 - 19440 - 500 });
  });
});
