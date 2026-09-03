import { formatMinorMoney, parseMoneyToMinor } from './listing-money';

describe('listing money conversion', () => {
  it('converts decimal input without floating-point arithmetic', () => {
    expect(parseMoneyToMinor('129.99', 2)).toBe(12999);
    expect(parseMoneyToMinor('129,9', 2)).toBe(12990);
    expect(parseMoneyToMinor('500', 0)).toBe(500);
    expect(parseMoneyToMinor('1.234', 3)).toBe(1234);
    expect(parseMoneyToMinor('', 2)).toBeNull();
  });

  it('rejects precision loss and unsafe integers', () => {
    expect(() => parseMoneyToMinor('1.001', 2)).toThrow(/2 decimal places/);
    expect(() => parseMoneyToMinor('9.1', 0)).toThrow(/does not use decimal/);
    expect(() => parseMoneyToMinor('9007199254740992', 0)).toThrow(/too large/);
  });

  it('formats an amount using the supplied ISO currency precision', () => {
    expect(formatMinorMoney(1234, 'KWD', 3)).toContain('1.234');
    expect(formatMinorMoney(null, null, null)).toBe('Price not provided');
  });
});
