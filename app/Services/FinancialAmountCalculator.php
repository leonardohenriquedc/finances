<?php

namespace App\Services;

use App\Models\FinancialPlan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class FinancialAmountCalculator
{
    /** @var array<string, BigDecimal> */
    private array $dailyFactors = [];

    /**
     * Rates are percentages. Simple interest is exact rational arithmetic until cents rounding.
     * Compound interest uses a 24-decimal nth root and fixed-scale exponentiation, never floats.
     * Time is nonnegative calendar days from competence to due date / 1, 30, or 365.
     * Total principal is split into cents; the first remainder installments receive one extra cent.
     */
    public function amount(FinancialPlan $plan, int $sequence, CarbonImmutable $dueDate): string
    {
        $principal = BigDecimal::of($plan->amount);

        if ($plan->kind === 'installment' && $plan->amount_mode === 'total') {
            $cents = $principal->multipliedBy(100)->toBigInteger();
            [$share, $remainder] = $cents->quotientAndRemainder($plan->installment_count);
            $principal = $share->plus($sequence <= $remainder->toInt() ? 1 : 0)->toBigDecimal()->withPointMovedLeft(2);
        }

        $days = max(0, (int) $plan->competence_date->diffInDays($dueDate, false));
        $periodDays = match ($plan->interest_period) {
            'daily' => 1,
            'monthly' => 30,
            'yearly' => 365,
        };
        $rate = BigDecimal::of($plan->interest_rate)->withPointMovedLeft(2);

        if ($plan->kind !== 'installment' || $plan->interest_type === 'none' || $rate->isZero() || $days === 0 || $principal->isZero()) {
            return (string) $principal->toScale(2, RoundingMode::HalfUp);
        }

        if ($plan->interest_type === 'simple') {
            $amount = $principal->toBigRational()->multipliedBy(
                $rate->toBigRational()->multipliedBy($days)->dividedBy($periodDays)->plus(1)
            )->toScale(2, RoundingMode::HalfUp);
        } else {
            $base = $rate->plus(1);
            $factor = $this->power($base, intdiv($days, $periodDays));
            $remainingDays = $days % $periodDays;
            if ($remainingDays > 0) {
                $key = $base.':'.$periodDays;
                $dailyFactor = $this->dailyFactors[$key] ??= $this->dailyFactor($base, $periodDays);
                $factor = $factor->multipliedBy($this->power($dailyFactor, $remainingDays));
            }
            $amount = $principal->multipliedBy($factor)->toScale(2, RoundingMode::HalfUp);
        }

        if ($amount->isGreaterThan('9999999999999.99')) {
            throw ValidationException::withMessages(['amount' => 'O valor projetado excede o limite monetário permitido.']);
        }

        return (string) $amount;
    }

    /**
     * Brick's integer nthRoot expands a 365th root to thousands of digits on pure PHP.
     * Bisection instead bounds both runtime and precision: 100 steps, 32 working decimals,
     * then 24 decimals for the daily factor. Comparisons cap powers above the target.
     */
    private function dailyFactor(BigDecimal $target, int $degree): BigDecimal
    {
        if ($degree === 1) {
            return $target;
        }
        $lower = BigDecimal::one();
        $upper = $target;
        $cap = $target->plus(1);
        for ($step = 0; $step < 100; $step++) {
            $middle = $lower->plus($upper)->dividedBy(2, 32, RoundingMode::HalfUp);
            $power = BigDecimal::one();
            $factor = $middle;
            $exponent = $degree;
            while ($exponent > 0) {
                if ($exponent % 2 === 1) {
                    $power = $power->multipliedBy($factor)->toScale(32, RoundingMode::HalfUp);
                    if ($power->isGreaterThan($target)) {
                        break;
                    }
                }
                $exponent = intdiv($exponent, 2);
                if ($exponent > 0) {
                    $factor = $factor->multipliedBy($factor)->toScale(32, RoundingMode::HalfUp);
                    if ($factor->isGreaterThan($cap)) {
                        $factor = $cap;
                    }
                }
            }
            if ($power->isGreaterThan($target)) {
                $upper = $middle;
            } else {
                $lower = $middle;
            }
        }

        return $lower->plus($upper)->dividedBy(2, 24, RoundingMode::HalfUp);
    }

    private function power(BigDecimal $base, int $exponent): BigDecimal
    {
        $result = BigDecimal::one();

        while ($exponent > 0) {
            if ($exponent % 2 === 1) {
                $result = $result->multipliedBy($base)->toScale(24, RoundingMode::HalfUp);
                if ($result->isGreaterThan('1000000000000000')) {
                    throw ValidationException::withMessages(['amount' => 'Os juros compostos excedem o limite monetário permitido.']);
                }
            }
            $exponent = intdiv($exponent, 2);
            if ($exponent > 0) {
                $base = $base->multipliedBy($base)->toScale(24, RoundingMode::HalfUp);
            }
        }

        return $result;
    }
}
