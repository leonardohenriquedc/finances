import test from 'node:test';
import assert from 'node:assert/strict';
import { paymentPriority, priorityLabels } from '../../resources/js/priority.ts';

for (const priority of ['when_possible', 'soon', 'essential']) {
    test(`preserves selected debit priority ${priority}`, () => {
        assert.equal(paymentPriority('debit', priority), priority);
    });
    test(`clears ${priority} when a plan is changed to credit`, () => {
        assert.equal(paymentPriority('credit', priority), null);
    });
}

test('defaults legacy or unselected debits without assigning priority to credits', () => {
    assert.equal(paymentPriority('debit', undefined), 'when_possible');
    assert.equal(paymentPriority('debit', null), 'when_possible');
    assert.equal(paymentPriority('credit', undefined), null);
    assert.equal(paymentPriority('credit', null), null);
});

test('displays the requested Portuguese payment priority levels in increasing urgency', () => {
    assert.deepEqual(Object.values(priorityLabels), ['Quando Puder', 'É bom pagar logo', 'Extrema Necessidade']);
});
