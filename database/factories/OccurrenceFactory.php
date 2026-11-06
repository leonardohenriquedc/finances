<?php

namespace Database\Factories;

use App\Models\FinancialPlan;
use App\Models\Occurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Occurrence> */
class OccurrenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'financial_plan_id' => FinancialPlan::factory(),
            'user_id' => fn (array $attributes): int => FinancialPlan::findOrFail($attributes['financial_plan_id'])->user_id,
            'sequence' => 1,
            'description' => fake()->sentence(3),
            'category' => 'General',
            'direction' => 'debit',
            'priority' => fn (array $attributes): ?string => $attributes['direction'] === 'debit' ? 'when_possible' : null,
            'kind' => 'single',
            'amount' => '100.00',
            'competence_date' => today()->toDateString(),
            'due_date' => today()->toDateString(),
            'paid_at' => null,
            'paid_amount' => null,
            'is_override' => false,
            'is_deleted' => false,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => ['paid_at' => $attributes['due_date'], 'paid_amount' => $attributes['amount']]);
    }
}
