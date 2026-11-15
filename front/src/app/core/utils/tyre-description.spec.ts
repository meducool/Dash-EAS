import { parseTyreDescription, withTyreIndexes } from './tyre-description';

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

describe('withTyreIndexes', () => {
  const tyre = { width: 215, height: 45, diameter: 16, loadIndex: '91', speedIndex: 'w', brand: 'Michelin', profile: 'Primacy 3' };

  it('puts the indexes right after the dimension', () => {
    expect(withTyreIndexes('MICHELIN 215/45R16 PRIMACY 3', tyre)).toBe('MICHELIN 215/45R16 91W PRIMACY 3');
    expect(withTyreIndexes('LAUFENN 195/65 R15 G FIT EQ+', { ...tyre, loadIndex: '91', speedIndex: 'H' })).toBe('LAUFENN 195/65 R15 91H G FIT EQ+');
    expect(withTyreIndexes('185/60R14', { ...tyre, loadIndex: '82', speedIndex: 'T' })).toBe('185/60R14 82T');
  });

  it('leaves a description that already has its indexes', () => {
    expect(withTyreIndexes('MICHELIN PILOT SPORT 4 225/45R17 91W', tyre)).toBe('MICHELIN PILOT SPORT 4 225/45R17 91W');
    expect(withTyreIndexes('215/45 R16 91 W Primacy', tyre)).toBe('215/45 R16 91 W Primacy');
  });

  it('leaves a text without readable dimension, and builds an empty one', () => {
    expect(withTyreIndexes('PRIMACY 3 MICHELIN', tyre)).toBe('PRIMACY 3 MICHELIN');
    expect(withTyreIndexes('245/70R17.5 143/141 HTR2+', { ...tyre, loadIndex: '141/139', speedIndex: 'M' })).toBe('245/70R17.5 143/141 HTR2+');
    expect(withTyreIndexes('', tyre)).toBe('MICHELIN 215/45R16 91W PRIMACY 3');
  });

  it('changes nothing without both indexes', () => {
    expect(withTyreIndexes('MICHELIN 215/45R16 PRIMACY 3', { ...tyre, speedIndex: '' })).toBe('MICHELIN 215/45R16 PRIMACY 3');
    expect(withTyreIndexes(null, { ...tyre, loadIndex: null })).toBe('');
  });

  it('keeps indexes written further in the text and never writes invalid ones', () => {
    expect(withTyreIndexes('PNEU PETLAS 195/75R16C 8PR PT825 107/105R', { ...tyre, loadIndex: '107/105', speedIndex: 'R' }))
      .toBe('PNEU PETLAS 195/75R16C 8PR PT825 107/105R');
    expect(withTyreIndexes('MICHELIN 215/45R16 PRIMACY 3', { ...tyre, loadIndex: '107', speedIndex: 'WW' })).toBe('MICHELIN 215/45R16 PRIMACY 3');
    expect(withTyreIndexes('MICHELIN 215/45R16 PRIMACY 3', { ...tyre, loadIndex: '16PR', speedIndex: 'R' })).toBe('MICHELIN 215/45R16 PRIMACY 3');
  });
});
