const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

/** Preserve exact cents even when a filtered aggregate exceeds Number.MAX_SAFE_INTEGER. */
export function money(value: string | number | null | undefined): string {
    const decimal = typeof value === 'number' ? value.toFixed(2) : value ?? '0.00';
    const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(decimal);
    if (!match) return '—';
    const [, sign, whole, fraction = '00'] = match;
    const negativeZero = sign === '-' && BigInt(whole!) === 0n;
    const integer = negativeZero ? -1n : BigInt(`${sign}${whole}`);
    return currency.formatToParts(integer).map(part => {
        if (part.type === 'fraction') return fraction.padEnd(2, '0');
        if (part.type === 'integer' && negativeZero) return '0';
        return part.value;
    }).join('');
}

/** Used only for displaying a chart's recurring net contribution, in exact integer cents. */
export function moneyDifference(credit: string, debit: string): string {
    const cents = (value: string): bigint => BigInt(value.replace('.', ''));
    const difference = cents(credit) - cents(debit);
    const absolute = difference < 0n ? -difference : difference;
    return `${difference < 0n ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
}
