import { parseTyreDescription } from './tyre-description';

const brands = [
  { id: 1, name: 'CONTINENTAL' },
  { id: 2, name: 'MICHELIN' },
  { id: 3, name: 'GOODRICH' },
  { id: 4, name: 'BF GOODRICH' },
  { id: 5, name: 'GITI' },
];

describe('parseTyreDescription', () => {
  it('reads the example typed in the product form', () => {
    expect(parseTyreDescription('205/80R16 104H XL CrossContact ATR', brands)).toEqual({
      width: 205,
      height: 80,
      diameter: 16,
      loadIndex: '104',
      speedIndex: 'H',
      reinforced: true,
      profile: 'CrossContact ATR',
    });
  });

  it('finds the brand, ZR sizes and OEM markings', () => {
    const parsed = parseTyreDescription('CONTINENTAL 225/45 ZR18 95Y SportContact 7 MO', brands);
    expect(parsed).toMatchObject({
      brandId: 1,
      width: 225,
      height: 45,
      diameter: 18,
      loadIndex: '95',
      speedIndex: 'Y',
      marking: 'MO',
      profile: 'SportContact 7',
    });
  });

  it('prefers the longest brand name', () => {
    expect(parseTyreDescription('BF Goodrich 265/70R17 121/118S All-Terrain T/A KO2', brands).brandId).toBe(4);
  });

  it('handles van sizes with double load index, run flat and season', () => {
    const parsed = parseTyreDescription('Pneu Michelin 195/70R15C 104/102R Agilis RFT hiver', brands);
    expect(parsed).toMatchObject({
      brandId: 2,
      width: 195,
      height: 70,
      diameter: 15,
      loadIndex: '104',
      speedIndex: 'R',
      runflat: true,
      season: 'winter',
      profile: 'Agilis',
    });
  });

  it('reads decimal and imperial sizes and the star marking', () => {
    expect(parseTyreDescription('31x10.50R15 109S', brands)).toMatchObject({ width: 31, height: 10.5, diameter: 15, loadIndex: '109' });
    expect(parseTyreDescription('245/40R19 98Y XL * RFT Potenza S005', brands)).toMatchObject({
      marking: '*',
      reinforced: true,
      runflat: true,
      profile: 'Potenza S005',
    });
  });

  it('keeps what it cannot read as the profile, and returns nothing for empty text', () => {
    expect(parseTyreDescription('GITI SYNERGY', brands)).toEqual({ brandId: 5, profile: 'SYNERGY' });
    expect(parseTyreDescription('', brands)).toEqual({});
    expect(parseTyreDescription(null)).toEqual({});
  });
});
