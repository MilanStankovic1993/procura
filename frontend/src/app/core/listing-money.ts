export function parseMoneyToMinor(value: string, minorUnit: number): number | null {
  const normalized = value.trim().replace(',', '.');

  if (normalized === '') {
    return null;
  }

  if (!Number.isInteger(minorUnit) || minorUnit < 0 || minorUnit > 6) {
    throw new Error('Unsupported currency precision.');
  }

  const match = /^(\d+)(?:\.(\d+))?$/.exec(normalized);

  if (match === null) {
    throw new Error('Enter a positive amount using digits and one decimal separator.');
  }

  const fraction = match[2] ?? '';

  if (fraction.length > minorUnit) {
    throw new Error(
      minorUnit === 0
        ? 'This currency does not use decimal places.'
        : `Use no more than ${minorUnit} decimal places.`,
    );
  }

  const digits = `${match[1]}${fraction.padEnd(minorUnit, '0')}`.replace(/^0+(?=\d)/, '');
  const amount = BigInt(digits);

  if (amount > BigInt(Number.MAX_SAFE_INTEGER)) {
    throw new Error('The amount is too large.');
  }

  return Number(amount);
}

export function formatMinorMoney(
  amount: number | null,
  currencyCode: string | null,
  minorUnit: number | null,
): string {
  if (amount === null || currencyCode === null || minorUnit === null) {
    return 'Price not provided';
  }

  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: currencyCode,
      minimumFractionDigits: minorUnit,
      maximumFractionDigits: minorUnit,
    }).format(amount / 10 ** minorUnit);
  } catch {
    return `${amount / 10 ** minorUnit} ${currencyCode}`;
  }
}
