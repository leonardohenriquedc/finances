import type { Direction, Priority } from './types';

export const priorityLabels: Record<Priority, string> = {
    when_possible: 'Quando Puder',
    soon: 'É bom pagar logo',
    essential: 'Extrema Necessidade',
};

export function paymentPriority(direction: Direction, priority: Priority | null | undefined): Priority | null {
    return direction === 'debit' ? priority ?? 'when_possible' : null;
}
