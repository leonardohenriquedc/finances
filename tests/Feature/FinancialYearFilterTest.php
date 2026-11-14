<?php

namespace Tests\Feature;

use App\Models\FinancialPlan;
use App\Models\Occurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialYearFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-15 12:00:00'));
    }

    /** @return array<string, array{array<string, int>, list<string>, string}> */
    public static function annualPeriods(): array
    {
        return [
            'specific year' => [['year' => 2026], ['2026-01-01', '2026-12-31'], '30.00'],
            'two years inclusive' => [['year_from' => 2026, 'year_to' => 2027], ['2026-01-01', '2026-12-31', '2027-01-01', '2027-12-31'], '100.00'],
            'same start and end' => [['year_from' => 2026, 'year_to' => 2026], ['2026-01-01', '2026-12-31'], '30.00'],
            'all years' => [[], ['2025-12-31', '2026-01-01', '2026-12-31', '2027-01-01', '2027-12-31', '2028-01-01'], '151.00'],
        ];
    }

    /** @param array<string, int> $filters
     * @param  list<string>  $expectedDates
     */
    #[DataProvider('annualPeriods')]
    public function test_annual_period_is_shared_by_paginated_totals_dashboard_and_csv(array $filters, array $expectedDates, string $total): void
    {
        $user = User::factory()->create();
        foreach (['2025-12-31' => '1.00', '2026-01-01' => '10.00', '2026-12-31' => '20.00', '2027-01-01' => '30.00', '2027-12-31' => '40.00', '2028-01-01' => '50.00'] as $date => $amount) {
            $this->occurrence($user, ['description' => 'Conta '.$date, 'due_date' => $date, 'amount' => $amount]);
        }
        $this->occurrence($user, ['description' => 'Excluída', 'is_deleted' => true]);
        $this->occurrence(User::factory()->create(), ['description' => 'Outro usuário']);
        $this->actingAs($user);
        $query = http_build_query($filters + ['per_page' => 1]);

        $this->getJson('/api/v1/occurrences?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', count($expectedDates))
            ->assertJsonPath('totals.debit', $total)->assertJsonPath('totals.credit', '0.00')
            ->assertJsonPath('data.0.due_date', $expectedDates[0]);
        $this->getJson('/api/v1/occurrences?'.$query.'&page=2')->assertOk()->assertJsonPath('totals.debit', $total);
        $dashboard = $this->getJson('/api/v1/dashboard?'.$query)->assertOk()
            ->assertJsonPath('data.totals.debit', $total)->assertJsonPath('data.categories.0.total', $total);
        $this->assertSame(array_map(fn (string $date): string => substr($date, 0, 7), $expectedDates), array_column($dashboard->json('data.months'), 'month'));
        $csv = $this->get('/api/v1/occurrences/export?'.$query.'&page=2')->assertOk()->assertDownload()->streamedContent();
        $this->assertStringNotContainsString('Outro usuário', $csv);
        $this->assertStringNotContainsString('Excluída', $csv);
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $descriptions = array_map(fn (string $line): ?string => str_getcsv($line, ',', '"', '')[0], array_slice($lines, 1));
        $this->assertSame(array_map(fn (string $date): string => 'Conta '.$date, $expectedDates), $descriptions);
    }

    public function test_year_range_intersects_month_date_interval_category_priority_and_status(): void
    {
        $user = User::factory()->create();
        $attributes = ['description' => 'Aluguel', 'category' => 'Moradia', 'direction' => 'debit', 'kind' => 'recurring', 'priority' => 'essential'];
        $first = $this->occurrence($user, [...$attributes, 'due_date' => '2026-02-01', 'amount' => '10.00']);
        $last = $this->occurrence($user, [...$attributes, 'due_date' => '2027-02-28', 'amount' => '20.00']);
        foreach ([['due_date' => '2025-02-01'], ['due_date' => '2028-02-01'], ['due_date' => '2026-03-01'], ['due_date' => '2027-02-01', 'category' => 'Lazer'], ['due_date' => '2027-02-01', 'priority' => 'soon'], ['due_date' => '2027-02-01', 'paid_at' => '2026-01-10', 'paid_amount' => '500.00']] as $difference) {
            $this->occurrence($user, array_replace($attributes, ['amount' => '500.00'], $difference));
        }
        $this->actingAs($user);
        $query = http_build_query(['year_from' => 2026, 'year_to' => 2027, 'month' => 2, 'from' => '2026-02-01', 'to' => '2027-02-28', 'category' => 'Moradia', 'priority' => 'essential', 'status' => 'pending', 'text' => 'Aluguel', 'direction' => 'debit', 'kind' => 'recurring']);

        $response = $this->getJson('/api/v1/occurrences?'.$query)->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.debit', '30.00');
        $this->assertSame([$first->id, $last->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/v1/dashboard?'.$query)->assertOk()->assertJsonPath('data.totals.debit', '30.00')
            ->assertJsonPath('data.settlement.pending', '30.00');
    }

    public function test_range_uses_due_year_and_actual_paid_amounts_including_late_payments(): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['direction' => 'credit', 'due_date' => '2026-12-31', 'competence_date' => '2025-12-01', 'amount' => '200.00', 'paid_at' => '2027-01-02', 'paid_amount' => '180.00']);
        $this->occurrence($user, ['due_date' => '2027-01-01', 'amount' => '100.00', 'paid_at' => '2027-01-03', 'paid_amount' => '110.00']);
        $this->occurrence($user, ['due_date' => '2028-01-01', 'amount' => '999.00', 'paid_at' => '2027-12-31', 'paid_amount' => '999.00']);
        $this->actingAs($user);

        $this->getJson('/api/v1/occurrences?year_from=2026&year_to=2027&status=realized')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('totals.credit', '200.00')->assertJsonPath('totals.debit', '100.00')
            ->assertJsonPath('totals.realized_credit', '180.00')->assertJsonPath('totals.realized_debit', '110.00')
            ->assertJsonPath('totals.realized_balance', '70.00')->assertJsonPath('totals.pending', '0.00');
        $this->getJson('/api/v1/dashboard?year_from=2026&year_to=2027&status=realized')->assertOk()
            ->assertJsonPath('data.settlement.realized', '290.00');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPeriods(): array
    {
        return [
            'missing start' => [['year_to' => 2027], 'year_from'],
            'missing end' => [['year_from' => 2026], 'year_to'],
            'reversed years' => [['year_from' => 2027, 'year_to' => 2026], 'year_to'],
            'start below minimum' => [['year_from' => 1899, 'year_to' => 2027], 'year_from'],
            'end above maximum' => [['year_from' => 2026, 'year_to' => 10000], 'year_to'],
            'noninteger' => [['year_from' => '2026.5', 'year_to' => 2027], 'year_from'],
            'array' => [['year_from' => [2026], 'year_to' => 2027], 'year_from'],
            'text' => [['year_from' => '2026 OR 1=1', 'year_to' => 2027], 'year_from'],
            'conflicting modes' => [['year' => 2026, 'year_from' => 2026, 'year_to' => 2027], 'year'],
        ];
    }

    /** @param array<string, mixed> $filters */
    #[DataProvider('invalidPeriods')]
    public function test_invalid_periods_return_422_on_every_filtered_endpoint(array $filters, string $field): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/occurrences', '/dashboard', '/occurrences/export'] as $path) {
            $this->getJson('/api/v1'.$path.'?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_reversed_years_have_a_portuguese_validation_message(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/occurrences?year_from=2027&year_to=2026')->assertUnprocessable()
            ->assertJsonPath('errors.year_to.0', 'O campo ano final deve ser maior ou igual a 2027.');
    }

    public function test_empty_year_fields_mean_all_years_and_a_valid_empty_account_has_zero_totals(): void
    {
        $this->occurrence(User::factory()->create(), ['description' => 'Privado']);
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/occurrences?year=&year_from=&year_to=')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('totals.debit', '0.00');
        $this->getJson('/api/v1/dashboard?year_from=2026&year_to=2027')->assertOk()
            ->assertJsonCount(0, 'data.months')->assertJsonPath('data.totals.debit', '0.00');
    }

    public function test_guest_cannot_read_financial_data_using_a_year_range(): void
    {
        foreach (['/occurrences', '/dashboard', '/occurrences/export'] as $path) {
            $this->getJson('/api/v1'.$path.'?year_from=2026&year_to=2027')->assertUnauthorized();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function occurrence(User $user, array $attributes = []): Occurrence
    {
        $attributes = array_replace(['due_date' => '2026-01-20', 'competence_date' => '2024-01-01', 'direction' => 'debit', 'amount' => '999.00'], $attributes);
        $plan = FinancialPlan::factory()->for($user)->create(['direction' => $attributes['direction'], 'first_due_date' => $attributes['due_date']]);

        return Occurrence::factory()->for($plan, 'financialPlan')->for($user)->create($attributes);
    }
}
