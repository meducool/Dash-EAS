import { amountInWords, integerInWords } from './amount-in-words';

describe('integerInWords', () => {
  it.each([
    [1, 'un'], [16, 'seize'], [17, 'dix-sept'], [21, 'vingt et un'], [22, 'vingt-deux'],
    [70, 'soixante-dix'], [71, 'soixante et onze'], [77, 'soixante-dix-sept'],
    [80, 'quatre-vingts'], [81, 'quatre-vingt-un'], [91, 'quatre-vingt-onze'], [99, 'quatre-vingt-dix-neuf'],
    [100, 'cent'], [101, 'cent un'], [200, 'deux cents'], [201, 'deux cent un'], [280, 'deux cent quatre-vingts'],
    [1000, 'mille'], [1500, 'mille cinq cents'], [2000, 'deux mille'], [80000, 'quatre-vingt mille'],
    [200000, 'deux cent mille'], [1000000, 'un million'], [2500000, 'deux millions cinq cent mille'],
    [15900, 'quinze mille neuf cents'],
  ])('%i → %s', (n, words) => {
    expect(integerInWords(n)).toBe(words);
  });
});

describe('amountInWords', () => {
  it('writes dirhams and centimes, capitalised', () => {
    expect(amountInWords(3000)).toBe('Trois mille dirhams');
    expect(amountInWords('8071.46')).toBe('Huit mille soixante et onze dirhams et quarante-six centimes');
    expect(amountInWords(1)).toBe('Un dirham');
    expect(amountInWords(0.01)).toBe('Zéro dirham et un centime');
    expect(amountInWords(1000000)).toBe('Un million de dirhams');
    expect(amountInWords(null)).toBe('Zéro dirham');
  });
});
