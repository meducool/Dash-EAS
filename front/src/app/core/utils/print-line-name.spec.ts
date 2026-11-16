import { labelCase, printLineName, tyreSiteName } from './print-line-name';

const tyre = (over: any = {}) => ({
  type: 'tyre',
  reference: 'MT2055017-HEC',
  profile: 'HECTORRA 5',
  brand: { name: 'MATADOR' },
  tyre: { tire_width: '205.00', tire_height: '50.00', tire_diameter: '17.00', tire_load_index: '93', tire_speed_index: 'Y', tire_marking: 'XL' },
  ...over,
});

describe('labelCase', () => {
  it('title-cases capitals, keeps short words, words with digits and mixed case', () => {
    expect(labelCase('MATADOR')).toBe('Matador');
    expect(labelCase('CINTURATO P7 BLUE')).toBe('Cinturato P7 Blue');
    expect(labelCase('BF GOODRICH')).toBe('BF Goodrich');
    expect(labelCase('ALL-TERRAIN T/A KO2')).toBe('All-Terrain T/A KO2');
    expect(labelCase('EfficientGrip Performance')).toBe('EfficientGrip Performance');
    expect(labelCase(null)).toBe('');
  });
});

describe('tyreSiteName', () => {
  it('names a tyre like the online shop', () => {
    expect(tyreSiteName(tyre())).toBe('Pneu Matador Hectorra 5 205/50 R17 93Y');
  });

  it('gives null for a non-tyre or a tyre without dimensions', () => {
    expect(tyreSiteName({ type: 'part', reference: 'FG-W55824' })).toBeNull();
    expect(tyreSiteName(tyre({ tyre: { tire_width: null, tire_diameter: null } }))).toBeNull();
  });
});

describe('printLineName', () => {
  it('tyre: shop name, POS reference and marking below', () => {
    expect(printLineName(tyre(), 'x')).toEqual({
      label: 'Pneu Matador Hectorra 5 205/50 R17 93Y',
      reference: 'MT2055017-HEC',
      details: 'XL',
    });
  });

  it('other articles keep their reference, else the fallback', () => {
    expect(printLineName({ type: 'part', reference: 'FG-W55824' }, 'Produit #1').label).toBe('FG-W55824');
    expect(printLineName(undefined, 'Produit #1').label).toBe('Produit #1');
  });
});
