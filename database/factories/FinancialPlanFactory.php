<?php

namespace Database\Factories;

use App\Models\FinancialPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinancialPlan> */
class FinancialPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'description' => fake()->sentence(3),
            'category' => 'General',
            'direction' => 'debit',
            'priority' => fn (array $attributes): ?string => $attributes['direction'] === 'debit' ? 'when_possible' : null,
            'kind' => 'single',
            'amount' => '100.00',
            'amount_mode' => 'total',
            'installment_count' => 1,
            'frequency' => 'monthly',
            'interest_type' => 'none',
            'interest_rate' => '0.0000',
            'interest_period' => 'monthly',
            'competence_date' => today()->toDateString(),
            'first_due_date' => today()->toDateString(),
            'end_date' => null,
            'occurrence_limit' => null,
            'state' => 'active',
        ];
    }

    public function installments(int $count = 3): static
    {
        return $this->state(fn (array $attributes): array => ['kind' => 'installment', 'installment_count' => $count]);
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes): array => ['kind' => 'recurring', 'amount_mode' => 'per_installment']);
    }
}
