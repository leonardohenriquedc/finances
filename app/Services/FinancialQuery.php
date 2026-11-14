<?php

namespace App\Services;

use App\Models\Occurrence;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

class FinancialQuery
{
    /** @param array<string, mixed> $filters
     * @return Builder<Occurrence>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $query = Occurrence::query()->where('user_id', $user->id)->where('is_deleted', false);
        foreach (['category', 'direction', 'kind', 'priority'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['month'])) {
            $query->whereMonth('due_date', $filters['month']);
        }
        if (! empty($filters['year'])) {
            $query->whereYear('due_date', $filters['year']);
        }
        if (! empty($filters['year_from']) && ! empty($filters['year_to'])) {
            $query->whereDate('due_date', '>=', $filters['year_from'].'-01-01')
                ->whereDate('due_date', '<=', $filters['year_to'].'-12-31');
        }
        if (! empty($filters['from'])) {
            $query->whereDate('due_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('due_date', '<=', $filters['to']);
        }
        if (! empty($filters['text'])) {
            $text = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['text']).'%';
            $query->where(function (Builder $search) use ($text): void {
                $search->whereRaw("description LIKE ? ESCAPE '!'", [$text])
                    ->orWhereRaw("category LIKE ? ESCAPE '!'", [$text]);
            });
        }
        match ($filters['status'] ?? null) {
            'pending' => $query->whereNull('paid_at'),
            'realized' => $query->whereNotNull('paid_at'),
            'open' => $query->whereNull('paid_at')->whereDate('due_date', '>=', today()),
            'overdue' => $query->whereNull('paid_at')->whereDate('due_date', '<', today()),
            'paid' => $query->whereNotNull('paid_at')->whereColumn('paid_at', '<=', 'due_date'),
            'paid_late' => $query->whereNotNull('paid_at')->whereColumn('paid_at', '>', 'due_date'),
            default => null,
        };

        return $query;
    }

    /** @param Builder<Occurrence> $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Occurrence>
     */
    public function sorted(Builder $query, array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'due_date';
        $order = $filters['order'] ?? 'asc';
        if ($sort === 'priority') {
            $query->orderByRaw("CASE WHEN direction = 'credit' OR priority IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("CASE priority WHEN 'when_possible' THEN 1 WHEN 'soon' THEN 2 WHEN 'essential' THEN 3 ELSE 0 END ".$order)
                ->orderBy('due_date');
        } elseif ($sort === 'status') {
            $query->orderByRaw("CASE WHEN paid_at IS NOT NULL AND paid_at > due_date THEN 'paid_late' WHEN paid_at IS NOT NULL THEN 'paid' WHEN due_date < ? THEN 'overdue' ELSE 'open' END ".$order, [today()->toDateString()]);
        } elseif ($sort === 'amount' && $query->getModel()->getConnection()->getDriverName() === 'sqlite') {
            // SQLite stores canonical two-decimal strings; integer cents avoid floating-point sorting.
            $query->orderByRaw("CAST(REPLACE(amount, '.', '') AS INTEGER) ".$order);
        } else {
            $query->orderBy($sort, $order);
        }

        return $query->orderBy('id');
    }

    /** @param Builder<Occurrence> $query
     * @return array{totals: array<string, string>, months: list<array<string, string>>, categories: list<array<string, string>>, settlement: array<string, string>}
     */
    public function aggregate(Builder $query): array
    {
        $totals = array_fill_keys(['credit', 'debit', 'projected_balance', 'realized_credit', 'realized_debit', 'realized_balance', 'pending', 'overdue'], '0.00');
        $months = [];
        $categories = [];
        $recurringSettlement = ['recurring_realized' => '0.00', 'recurring_pending' => '0.00'];
        foreach ((clone $query)->reorder()->lazyById(500) as $row) {
            $month = $row->due_date->format('Y-m');
            $months[$month] ??= ['month' => $month, ...array_fill_keys(['credit', 'debit', 'recurring_credit', 'recurring_debit', 'realized', 'pending', 'balance', 'realized_balance'], '0.00')];
            $categories[$row->category] ??= ['category' => $row->category, 'credit' => '0.00', 'debit' => '0.00', 'total' => '0.00', 'recurring_credit' => '0.00', 'recurring_debit' => '0.00'];
            $this->add($totals, $row->direction, $row->amount);
            $this->add($months[$month], $row->direction, $row->amount);
            $this->add($categories[$row->category], $row->direction, $row->amount);
            $this->add($categories[$row->category], 'total', $row->amount);
            if ($row->kind === 'recurring') {
                $this->add($months[$month], 'recurring_'.$row->direction, $row->amount);
                $this->add($categories[$row->category], 'recurring_'.$row->direction, $row->amount);
                $this->add($recurringSettlement, $row->paid_at !== null ? 'recurring_realized' : 'recurring_pending', $row->paid_at !== null ? $row->paid_amount : $row->amount);
            }
            if ($row->paid_at !== null) {
                $this->add($totals, 'realized_'.$row->direction, $row->paid_amount);
                $this->add($months[$month], 'realized', $row->paid_amount);
                $signed = $row->direction === 'credit' ? $row->paid_amount : (string) BigDecimal::of($row->paid_amount)->negated();
                $this->add($months[$month], 'realized_balance', $signed);
            } else {
                $this->add($totals, 'pending', $row->amount);
                $this->add($months[$month], 'pending', $row->amount);
                if ($row->status === 'overdue') {
                    $this->add($totals, 'overdue', $row->amount);
                }
            }
        }
        $totals['projected_balance'] = (string) BigDecimal::of($totals['credit'])->minus($totals['debit']);
        $totals['realized_balance'] = (string) BigDecimal::of($totals['realized_credit'])->minus($totals['realized_debit']);
        ksort($months);
        $balance = BigDecimal::of('0.00');
        $realizedBalance = BigDecimal::of('0.00');
        foreach ($months as &$monthRow) {
            $balance = $balance->plus($monthRow['credit'])->minus($monthRow['debit']);
            $realizedBalance = $realizedBalance->plus($monthRow['realized_balance']);
            $monthRow['balance'] = (string) $balance;
            $monthRow['realized_balance'] = (string) $realizedBalance;
        }
        unset($monthRow);
        ksort($categories);

        return [
            'totals' => $totals,
            'months' => array_values($months),
            'categories' => array_values($categories),
            'settlement' => ['realized' => (string) BigDecimal::of($totals['realized_credit'])->plus($totals['realized_debit']), 'pending' => $totals['pending'], ...$recurringSettlement],
        ];
    }

    /** @param array<string, string> $values */
    private function add(array &$values, string $key, string $amount): void
    {
        $values[$key] = (string) BigDecimal::of($values[$key])->plus($amount);
    }
}
