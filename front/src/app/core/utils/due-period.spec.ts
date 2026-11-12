import { duePeriod, duePeriodLegend, monthKey, monthKeyFromToday, monthOffset, monthTitle } from './due-period';

describe('duePeriod', () => {
  const today = new Date(2026, 10, 10); // 10 novembre 2026

  it('classifies the current month', () => {
    expect(duePeriod('2026-11-11', today)).toBe('current');
    expect(duePeriod('2026-11-30', today)).toBe('current');
  });

  it('classifies the next month', () => {
    expect(duePeriod('2026-12-01', today)).toBe('next');
    expect(duePeriod('2026-12-31', today)).toBe('next');
  });

  it('classifies every later month, across the year change', () => {
    expect(duePeriod('2027-01-10', today)).toBe('later');
    expect(duePeriod('2027-02-26', today)).toBe('later');
    expect(duePeriod('2028-11-10', today)).toBe('later');
  });

  it('rolls December into January as the next month', () => {
    const december = new Date(2026, 11, 15);
    expect(duePeriod('2026-12-20', december)).toBe('current');
    expect(duePeriod('2027-01-05', december)).toBe('next');
    expect(duePeriod('2027-02-05', december)).toBe('later');
  });

  it('reads the month from the string, whatever the time part', () => {
    expect(duePeriod('2026-12-01T00:00:00.000000Z', today)).toBe('next');
    expect(duePeriod('2026-11-30 23:59:59', today)).toBe('current');
  });

  it('treats an overdue date as due now, and an unreadable one as later', () => {
    expect(duePeriod('2026-10-31', today)).toBe('current');
    expect(duePeriod(null, today)).toBe('later');
    expect(duePeriod('n/a', today)).toBe('later');
  });
});

describe('duePeriodLegend', () => {
  it('names the three buckets after the months they cover', () => {
    const legend = duePeriodLegend(new Date(2026, 10, 10));

    expect(legend.map((l) => l.period)).toEqual(['current', 'next', 'later']);
    expect(legend[0].months).toBe('novembre 2026');
    expect(legend[1].months).toBe('décembre 2026');
    expect(legend[2].months).toBe('janvier 2027 et après');
  });
});

describe('month helpers', () => {
  const today = new Date(2026, 11, 15); // 15 décembre 2026

  it('reads the month key from the string', () => {
    expect(monthKey('2027-01-31T23:00:00Z')).toBe('2027-01');
    expect(monthKey(null)).toBeNull();
  });

  it('counts months from today across the year change', () => {
    expect(monthOffset('2026-12', today)).toBe(0);
    expect(monthOffset('2027-01', today)).toBe(1);
    expect(monthOffset('2027-02', today)).toBe(2);
    expect(monthOffset('2026-11', today)).toBe(-1);
  });

  it('builds keys and capitalised French titles', () => {
    expect(monthKeyFromToday(0, today)).toBe('2026-12');
    expect(monthKeyFromToday(2, today)).toBe('2027-02');
    expect(monthTitle('2027-02')).toBe('Février 2027');
  });
});
