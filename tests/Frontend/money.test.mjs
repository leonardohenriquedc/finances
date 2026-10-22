import test from 'node:test';
import assert from 'node:assert/strict';
import { money, moneyDifference } from '../../resources/js/money.ts';

const normalized = value => money(value).replace(/\u00a0/g, ' ');

for (const [input, expected] of [
    ['1234.56', 'R$ 1.234,56'],
    ['89999999999999.91', 'R$ 89.999.999.999.999,91'],
    ['-89999999999999.91', '-R$ 89.999.999.999.999,91'],
    ['-0.01', '-R$ 0,01'],
    ['0.00', 'R$ 0,00'],
    ['12.5', 'R$ 12,50'],
    [null, 'R$ 0,00'],
    [undefined, 'R$ 0,00'],
    ['invalid', '—'],
]) {
    test(`formats ${input} without losing cents`, () => assert.equal(normalized(input), expected));
}

test('subtracts chart contributions as exact cents above safe integer range', () => {
    assert.equal(moneyDifference('89999999999999.91', '89999999999999.90'), '0.01');
    assert.equal(moneyDifference('0.00', '0.01'), '-0.01');
    assert.equal(moneyDifference('12.30', '12.30'), '0.00');
});
