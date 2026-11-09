<?php

namespace Tests\Feature;

use App\Models\FinancialPlan;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\FinancialPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialValueHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-01-15 12:00:00', config('app.timezone')));
    }

    /** @return array<string, array{string, string}> */
    public static function multiPlans(): array
    {
        return [
            'debit installments' => ['installment', 'debit'],
            'credit installments' => ['installment', 'credit'],
            'debit recurring' => ['recurring', 'debit'],
            'credit recurring' => ['recurring', 'credit'],
        ];
    }

    #[DataProvider('multiPlans')]
    public function test_amount_revision_changes_only_strictly_future_unpaid_nonoverride_rows(string $kind, string $direction): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, [
            'kind' => $kind, 'direction' => $direction, 'first_due_date' => '2026-01-11',
            'amount' => $kind === 'installment' ? '1000.00' : '100.00',
            'installment_count' => 10, 'occurrence_limit' => 10,
        ]);
        $rows = $plan->occurrences()->orderBy('sequence')->get();
        $this->actingAs($user);
        $this->putJson('/api/v1/occurrences/'.$rows[1]->id.'/payment', ['paid_at' => '2026-01-12', 'paid_amount' => '93.25'])->assertOk();
        $this->putJson('/api/v1/occurrences/'.$rows[2]->id.'/payment', ['paid_at' => '2026-01-15', 'paid_amount' => '107.75'])->assertOk();
        $this->putJson('/api/v1/occurrences/'.$rows[5]->id.'/payment', ['paid_at' => '2026-01-15', 'paid_amount' => '80.00'])->assertOk();
        $this->patchJson('/api/v1/occurrences/'.$rows[6]->id, ['amount' => '123.45', 'description' => 'Exceção'])->assertOk();
        $this->deleteJson('/api/v1/occurrences/'.$rows[7]->id)->assertNoContent();
        $frozen = $this->snapshot($plan, range(1, 8));
        $this->travelTo(CarbonImmutable::parse('2026-01-15 13:00:00', config('app.timezone')));
        $newAmount = $kind === 'installment' ? '2000.00' : '200.00';

        $this->putJson('/api/v1/plans/'.$plan->id, [
            'amount' => $newAmount, 'description' => 'Revisado', 'priority' => 'essential',
        ])->assertOk()->assertJsonPath('data.amount', $newAmount);

        $this->assertSame($frozen, $this->snapshot($plan, range(1, 8)));
        $this->assertSame(['200.00', '200.00'], $plan->occurrences()->where('sequence', '>=', 9)->orderBy('sequence')->pluck('amount')->all());
        $this->assertDatabaseHas('occurrences', ['financial_plan_id' => $plan->id, 'sequence' => 9, 'description' => 'Revisado', 'priority' => $direction === 'debit' ? 'essential' : null]);
        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'amount' => $newAmount]);
        $this->assertSame('2026-01-15', $plan->fresh()->value_effective_after?->toDateString());
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->assertSame($frozen, $this->snapshot($plan, range(1, 8)));
        $this->assertSame(10, $plan->occurrences()->count());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function financialChanges(): array
    {
        return [
            'amount' => [['amount' => '1200.00']],
            'amount mode' => [['amount_mode' => 'per_installment']],
            'installment count' => [['installment_count' => 12]],
            'interest type' => [['interest_type' => 'compound']],
            'interest rate' => [['interest_rate' => '2.0000']],
            'interest period' => [['interest_period' => 'daily']],
            'competence date' => [['competence_date' => '2026-01-02']],
            'first due date' => [['first_due_date' => '2026-01-12']],
            'frequency' => [['frequency' => 'weekly']],
            'kind' => [['kind' => 'recurring']],
        ];
    }

    #[DataProvider('financialChanges')]
    public function test_calculation_term_changes_freeze_every_existing_historical_row(array $changes): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['interest_type' => 'simple', 'interest_rate' => '1.0000']);
        $before = $this->snapshot($plan, range(1, 5));
        $this->actingAs($user);
        $this->travelTo(CarbonImmutable::parse('2026-01-15 13:00:00'));

        $this->putJson('/api/v1/plans/'.$plan->id, $changes)->assertOk();

        $this->assertSame($before, $this->snapshot($plan, range(1, 5)));
        $this->assertSame('2026-01-15', $plan->fresh()->value_effective_after?->toDateString());
        foreach ($changes as $field => $expected) {
            $actual = $plan->fresh()->{$field};
            $this->assertSame($expected, in_array($field, ['competence_date', 'first_due_date'], true) ? $actual->toDateString() : $actual);
        }
    }

    public function test_interest_revision_uses_new_rate_only_for_future_installments(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, [
            'amount' => '300.00', 'installment_count' => 3, 'first_due_date' => '2026-01-14',
            'competence_date' => '2026-01-14', 'interest_type' => 'simple',
            'interest_rate' => '1.0000', 'interest_period' => 'daily',
        ]);
        $before = $this->snapshot($plan, [1, 2]);
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['interest_rate' => '2.0000'])->assertOk();

        $this->assertSame($before, $this->snapshot($plan, [1, 2]));
        $this->assertSame(['100.00', '101.00', '104.00'], $plan->occurrences()->orderBy('sequence')->pluck('amount')->all());
    }

    public function test_revised_total_is_divided_by_original_count_not_remaining_balance(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['amount' => '300.00', 'installment_count' => 3, 'first_due_date' => '2026-01-14']);
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '600.00'])->assertOk()->assertJsonPath('data.amount', '600.00');

        $this->assertSame(['100.00', '100.00', '200.00'], $plan->occurrences()->orderBy('sequence')->pluck('amount')->all());
        $this->assertSame(400, (int) $plan->occurrences()->sum('amount'));
    }

    /** @return array<string, array{int, array<int, string>}> */
    public static function anchors(): array
    {
        return [
            'past anchor cannot rewrite history' => [2, ['100.00', '100.00', '100.00', '200.00', '200.00']],
            'future anchor additionally excludes earlier future rows' => [5, ['100.00', '100.00', '100.00', '100.00', '200.00']],
        ];
    }

    #[DataProvider('anchors')]
    public function test_explicit_future_scope_obeys_both_anchor_and_today(int $sequence, array $expected): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['amount' => '500.00', 'installment_count' => 5, 'first_due_date' => '2026-01-13']);
        $anchor = $plan->occurrences()->where('sequence', $sequence)->sole();
        $before = $this->snapshot($plan, range(1, max(3, $sequence - 1)));
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['scope' => 'future', 'occurrence_id' => $anchor->id, 'amount' => '1000.00'])->assertOk();

        $this->assertSame($before, $this->snapshot($plan, range(1, max(3, $sequence - 1))));
        $this->assertSame($expected, $plan->occurrences()->orderBy('sequence')->pluck('amount')->all());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function metadataChanges(): array
    {
        return [
            'priority only' => [['priority' => 'essential']],
            'description only' => [['description' => 'Revisado']],
            'normalized monetary values with metadata' => [[
                'amount' => '1000', 'interest_rate' => '0', 'installment_count' => '10',
                'priority' => 'essential', 'description' => 'Revisado',
            ]],
        ];
    }

    #[DataProvider('metadataChanges')]
    public function test_metadata_without_a_financial_change_keeps_existing_all_scope_semantics(array $changes): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, $changes)->assertOk();

        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'value_effective_after' => null]);
        $this->assertSame(10, $plan->occurrences()->count());
        $this->assertDatabaseHas('occurrences', [
            'financial_plan_id' => $plan->id, 'sequence' => 1, 'amount' => '100.00',
            'description' => $changes['description'] ?? 'Plano original',
            'priority' => $changes['priority'] ?? 'when_possible',
        ]);
    }

    #[DataProvider('multiPlans')]
    public function test_metadata_after_financial_revision_preserves_frozen_history_and_new_terms_generate_once(string $kind, string $direction): void
    {
        config(['finance.projection_months' => 1]);
        $user = User::factory()->create();
        $plan = $this->plan($user, [
            'kind' => $kind, 'direction' => $direction, 'frequency' => 'monthly',
            'first_due_date' => '2026-01-15', 'installment_count' => 4, 'occurrence_limit' => 4,
            'amount' => $kind === 'installment' ? '400.00' : '100.00',
        ]);
        $before = $this->snapshot($plan, [1]);
        $this->actingAs($user);
        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => $kind === 'installment' ? '800.00' : '200.00'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-02-16 12:00:00'));
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $anchor = $plan->occurrences()->where('sequence', 3)->sole();

        $this->putJson('/api/v1/plans/'.$plan->id, [
            'scope' => 'future', 'occurrence_id' => $anchor->id, 'description' => 'Metadados futuros', 'priority' => 'essential',
        ])->assertOk();
        $this->assertSame('Plano original', $plan->occurrences()->where('sequence', 2)->sole()->description);
        $this->putJson('/api/v1/plans/'.$plan->id, ['priority' => 'soon'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-03-16 12:00:00'));
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);

        $this->assertSame($before, $this->snapshot($plan, [1]));
        $this->assertSame(['100.00', '200.00', '200.00', '200.00'], $plan->occurrences()->orderBy('sequence')->pluck('amount')->all());
        $this->assertDatabaseHas('occurrences', [
            'financial_plan_id' => $plan->id, 'sequence' => 4, 'description' => 'Metadados futuros',
            'priority' => $direction === 'debit' ? 'soon' : null, 'amount' => '200.00',
        ]);
        $this->assertSame('2026-01-15', $plan->fresh()->value_effective_after?->toDateString());
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function anchoredRevisionMetadataEdits(): array
    {
        return [
            'January 15 revision then priority' => ['2026-01-15', '200.00', ['priority' => 'essential']],
            'January 15 revision then description' => ['2026-01-15', '200.00', ['description' => 'Descrição revisada']],
            'January 18 revision then priority' => ['2026-01-18', '275.00', ['priority' => 'essential']],
            'January 18 revision then description' => ['2026-01-18', '275.00', ['description' => 'Descrição revisada']],
        ];
    }

    #[DataProvider('anchoredRevisionMetadataEdits')]
    public function test_later_metadata_edit_does_not_reprice_history_between_financial_floor_and_future_anchor(string $revisionDate, string $newAmount, array $changes): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'recurring', 'amount' => '100.00', 'occurrence_limit' => 20]);
        $this->travelTo(CarbonImmutable::parse($revisionDate.' 12:00:00', config('app.timezone')));
        $floorSequence = (int) substr($revisionDate, -2) - 10;
        $frozen = $this->snapshot($plan, range(1, $floorSequence));
        $anchor = $plan->occurrences()->where('sequence', 15)->sole();
        $this->actingAs($user);
        $this->putJson('/api/v1/plans/'.$plan->id, [
            'scope' => 'future', 'occurrence_id' => $anchor->id, 'amount' => $newAmount,
        ])->assertOk()->assertJsonPath('data.amount', $newAmount);
        $this->assertSame(array_fill(0, 14, '100.00'), $plan->occurrences()->where('sequence', '<', 15)->orderBy('sequence')->pluck('amount')->all());
        $history = $this->snapshot($plan, range($floorSequence + 1, 13));
        $this->travelTo(CarbonImmutable::parse('2026-01-23 12:00:00', config('app.timezone')));

        $this->putJson('/api/v1/plans/'.$plan->id, ['scope' => 'all', ...$changes])->assertOk();

        $this->assertSame($frozen, $this->snapshot($plan, range(1, $floorSequence)));
        $this->assertSame(array_fill(0, 13, '100.00'), $plan->occurrences()->where('sequence', '<=', 13)->orderBy('sequence')->pluck('amount')->all());
        foreach ($history as $before) {
            $row = $plan->occurrences()->where('sequence', $before['sequence'])->sole();
            $expected = array_replace($before, $changes);
            $actual = $row->getAttributes();
            unset($expected['updated_at'], $actual['updated_at']);
            $this->assertSame($expected, $actual);
        }
        $this->assertSame($revisionDate, $plan->fresh()->value_effective_after?->toDateString());
        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'amount' => $newAmount, ...$changes]);
        $this->assertSame(array_fill(0, 6, $newAmount), $plan->occurrences()->where('sequence', '>=', 15)->orderBy('sequence')->pluck('amount')->all());
        $afterMetadata = $this->snapshot($plan);

        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);

        $this->assertSame($afterMetadata, $this->snapshot($plan));
        $this->assertSame(range(1, 20), $plan->occurrences()->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame(20, $plan->occurrences()->distinct()->count('id'));
    }

    #[DataProvider('metadataChanges')]
    public function test_metadata_without_any_revision_preserves_exact_historical_calculated_amounts_and_ids(array $changes): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['interest_type' => 'simple', 'interest_rate' => '1.0000', 'interest_period' => 'daily']);
        $history = $this->snapshot($plan, range(1, 5));
        $this->actingAs($user);
        $this->travelTo(CarbonImmutable::parse('2026-01-15 13:00:00', config('app.timezone')));
        $metadata = array_intersect_key($changes, array_flip(['priority', 'description']));

        $this->putJson('/api/v1/plans/'.$plan->id, $metadata)->assertOk();

        $this->assertSame(['110.00', '111.00', '112.00', '113.00', '114.00'], $plan->occurrences()->where('sequence', '<=', 5)->orderBy('sequence')->pluck('amount')->all());
        foreach ($history as $before) {
            $row = $plan->occurrences()->where('sequence', $before['sequence'])->sole();
            $expected = array_replace($before, $metadata);
            $actual = $row->getAttributes();
            unset($expected['updated_at'], $actual['updated_at']);
            $this->assertSame($expected, $actual);
        }
        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'value_effective_after' => null, ...$metadata]);
        $this->assertSame(10, $plan->occurrences()->count());
    }

    public function test_repeated_revisions_freeze_latest_history_and_advance_the_date_floor(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'recurring', 'amount' => '100.00', 'first_due_date' => '2026-01-14', 'occurrence_limit' => 6]);
        $original = $this->snapshot($plan, [1, 2]);
        $this->actingAs($user);
        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '200.00'])->assertOk();
        $latestHistory = $this->snapshot($plan, [3, 4]);
        $this->travelTo(CarbonImmutable::parse('2026-01-17 12:00:00'));

        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '300.00'])->assertOk();
        $this->putJson('/api/v1/plans/'.$plan->id, ['description' => 'Depois das revisões'])->assertOk();
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);

        $this->assertSame($original, $this->snapshot($plan, [1, 2]));
        $this->assertSame($latestHistory, $this->snapshot($plan, [3, 4]));
        $this->assertSame(['100.00', '100.00', '200.00', '200.00', '300.00', '300.00'], $plan->occurrences()->orderBy('sequence')->pluck('amount')->all());
        $this->assertSame('2026-01-17', $plan->fresh()->value_effective_after?->toDateString());
    }

    public function test_missing_past_and_today_rows_are_materialized_using_old_terms_before_revision(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'recurring', 'amount' => '100.00', 'first_due_date' => '2026-01-13', 'occurrence_limit' => 5]);
        $plan->occurrences()->whereIn('sequence', [1, 3])->delete();
        $existing = $this->snapshot($plan, [2]);
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '200.00', 'description' => 'Novos termos'])->assertOk();
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);

        $this->assertSame($existing, $this->snapshot($plan, [2]));
        $this->assertSame(['100.00', '100.00', '100.00', '200.00', '200.00'], $plan->occurrences()->orderBy('sequence')->pluck('amount')->all());
        foreach ([1 => '2026-01-13', 3 => '2026-01-15'] as $sequence => $dueDate) {
            $this->assertDatabaseHas('occurrences', ['financial_plan_id' => $plan->id, 'sequence' => $sequence, 'due_date' => $dueDate.' 00:00:00', 'description' => 'Plano original', 'is_deleted' => false]);
        }
    }

    public function test_backdated_schedule_change_cannot_invent_missing_new_term_history_during_generation(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'recurring', 'amount' => '100.00', 'first_due_date' => '2026-01-20', 'occurrence_limit' => 8]);
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['first_due_date' => '2026-01-10', 'amount' => '200.00'])->assertOk();
        $this->putJson('/api/v1/plans/'.$plan->id, ['description' => 'Nova agenda'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-02-01 12:00:00'));
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);

        $this->assertSame([7, 8], $plan->occurrences()->where('is_deleted', false)->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame(['2026-01-16', '2026-01-17'], $plan->occurrences()->where('is_deleted', false)->orderBy('sequence')->get()->map(fn (Occurrence $row): string => $row->due_date->toDateString())->all());
        $this->assertSame('2026-01-10', $plan->fresh()->first_due_date->toDateString());
        $this->assertSame('2026-01-15', $plan->fresh()->value_effective_after?->toDateString());
    }

    public function test_paused_missing_dates_are_tombstoned_with_old_terms_and_never_recreated(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-11 12:00:00'));
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'recurring', 'amount' => '100.00', 'occurrence_limit' => 10]);
        $this->actingAs($user);
        $this->travelTo(CarbonImmutable::parse('2026-01-13 12:00:00'));
        $this->postJson('/api/v1/plans/'.$plan->id.'/lifecycle', ['action' => 'pause'])->assertOk();
        $before = $this->snapshot($plan, [1, 2]);
        $this->travelTo(CarbonImmutable::parse('2026-01-16 12:00:00'));
        $this->postJson('/api/v1/plans/'.$plan->id.'/lifecycle', ['action' => 'resume'])->assertOk();

        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '200.00'])->assertOk();
        $this->putJson('/api/v1/plans/'.$plan->id, ['priority' => 'essential'])->assertOk();
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);

        $this->assertSame($before, $this->snapshot($plan, [1, 2]));
        $this->assertSame([3, 4, 5], $plan->occurrences()->where('is_deleted', true)->orderBy('sequence')->pluck('sequence')->all());
        foreach ([3, 4, 5] as $sequence) {
            $this->assertDatabaseHas('occurrences', ['financial_plan_id' => $plan->id, 'sequence' => $sequence, 'is_deleted' => true, 'amount' => '100.00']);
        }
        $this->assertSame([1, 2, 6, 7, 8, 9, 10], $plan->occurrences()->where('is_deleted', false)->orderBy('sequence')->pluck('sequence')->all());
    }

    /** @return array<string, array{string, string, array<string, string>, string}> */
    public static function historicalPatches(): array
    {
        $cases = [];
        foreach (['installment', 'recurring'] as $kind) {
            foreach (['2026-01-14', '2026-01-15'] as $dueDate) {
                $cases[$kind.' '.$dueDate.' amount'] = [$kind, $dueDate, ['amount' => '101.00'], 'amount'];
                $cases[$kind.' '.$dueDate.' move to future'] = [$kind, $dueDate, ['due_date' => '2026-01-20'], 'due_date'];
                $cases[$kind.' '.$dueDate.' combined bypass'] = [$kind, $dueDate, ['amount' => '101.00', 'due_date' => '2026-01-20'], 'amount'];
            }
            foreach (['2026-01-14', '2026-01-15'] as $target) {
                $cases[$kind.' future moved to '.$target] = [$kind, '2026-01-16', ['due_date' => $target], 'due_date'];
            }
        }

        return $cases;
    }

    #[DataProvider('historicalPatches')]
    public function test_multi_occurrence_patch_returns_422_for_historical_financial_mutation(string $kind, string $dueDate, array $changes, string $field): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => $kind, 'amount' => '100.00', 'amount_mode' => 'per_installment', 'first_due_date' => $dueDate]);
        $row = $plan->occurrences()->where('sequence', 1)->sole();
        $before = $this->snapshot($plan);
        $planBefore = $plan->fresh()->getAttributes();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$row->id, $changes)->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame($before, $this->snapshot($plan));
        $this->assertSame($planBefore, $plan->fresh()->getAttributes());
    }

    #[DataProvider('multiPlans')]
    public function test_historical_occurrence_accepts_numerically_identical_amount_and_unchanged_dates_with_metadata(string $kind, string $direction): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => $kind, 'direction' => $direction, 'amount' => '100.00', 'amount_mode' => 'per_installment']);
        $row = $plan->occurrences()->where('sequence', 1)->sole();
        $planBefore = $plan->fresh()->getAttributes();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$row->id, [
            'amount' => '100', 'due_date' => '2026-01-11', 'competence_date' => '2026-01-01',
            'priority' => 'essential', 'description' => 'Observação histórica',
        ])->assertOk()->assertJsonPath('data.amount', '100.00')->assertJsonPath('data.is_override', true);

        $this->assertDatabaseHas('occurrences', ['id' => $row->id, 'amount' => '100.00', 'due_date' => '2026-01-11 00:00:00', 'description' => 'Observação histórica', 'priority' => $direction === 'debit' ? 'essential' : null]);
        $this->assertSame($planBefore, $plan->fresh()->getAttributes());
    }

    #[DataProvider('multiPlans')]
    public function test_future_occurrence_accepts_amount_and_future_date_changes(string $kind, string $direction): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => $kind, 'direction' => $direction, 'first_due_date' => '2026-01-16']);
        $row = $plan->occurrences()->where('sequence', 1)->sole();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$row->id, ['amount' => '123.45', 'due_date' => '2026-01-20'])->assertOk();

        $this->assertDatabaseHas('occurrences', ['id' => $row->id, 'amount' => '123.45', 'due_date' => '2026-01-20 00:00:00', 'is_override' => true]);
    }

    public function test_single_occurrence_remains_financially_editable_in_the_past(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'single', 'amount' => '100.00']);
        $row = $plan->occurrences()->sole();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$row->id, ['amount' => '150.00', 'due_date' => '2026-01-10'])->assertOk();

        $this->assertDatabaseHas('occurrences', ['id' => $row->id, 'amount' => '150.00', 'due_date' => '2026-01-10 00:00:00', 'is_override' => true]);
    }

    public function test_paid_occurrence_patch_cannot_clear_payment_fields_or_change_persisted_history(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $row = $plan->occurrences()->where('sequence', 1)->sole();
        $this->actingAs($user);
        $this->putJson('/api/v1/occurrences/'.$row->id.'/payment', ['paid_at' => '2026-01-15', 'paid_amount' => '98.75'])->assertOk();
        $before = $this->snapshot($plan);

        $this->patchJson('/api/v1/occurrences/'.$row->id, ['amount' => '200.00', 'paid_at' => null, 'paid_amount' => null])->assertUnprocessable();

        $this->assertSame($before, $this->snapshot($plan));
    }

    public function test_financial_edits_of_another_users_plan_and_occurrence_return_404_without_mutation(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $row = $plan->occurrences()->where('sequence', 1)->sole();
        $before = $this->snapshot($plan);
        $planBefore = $plan->fresh()->getAttributes();
        $this->actingAs(User::factory()->create());

        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '2000.00'])->assertNotFound();
        $this->patchJson('/api/v1/occurrences/'.$row->id, ['amount' => '200.00'])->assertNotFound();

        $this->assertSame($before, $this->snapshot($plan));
        $this->assertSame($planBefore, $plan->fresh()->getAttributes());
    }

    public function test_financial_date_floor_uses_application_calendar_day_at_utc_boundary(): void
    {
        config(['app.timezone' => 'America/Sao_Paulo']);
        $this->travelTo(CarbonImmutable::parse('2026-01-15 23:30:00', 'America/Sao_Paulo'));
        $user = User::factory()->create();
        $plan = $this->plan($user, ['amount' => '300.00', 'installment_count' => 3, 'first_due_date' => '2026-01-14']);
        $before = $this->snapshot($plan, [1, 2]);
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '600.00'])->assertOk();

        $this->assertSame($before, $this->snapshot($plan, [1, 2]));
        $this->assertSame('200.00', $plan->occurrences()->where('sequence', 3)->sole()->amount);
        $this->assertSame('2026-01-15', $plan->fresh()->value_effective_after?->toDateString());
    }

    /** @param array<string, mixed> $changes */
    private function plan(User $user, array $changes = []): FinancialPlan
    {
        return app(FinancialPlanService::class)->create($user, array_replace([
            'description' => 'Plano original', 'category' => 'Moradia', 'direction' => 'debit',
            'kind' => 'installment', 'amount' => '1000.00', 'amount_mode' => 'total',
            'installment_count' => 10, 'occurrence_limit' => 10, 'frequency' => 'daily', 'competence_date' => '2026-01-01',
            'first_due_date' => '2026-01-11',
        ], $changes));
    }

    /**
     * @param  array<int, int>|null  $sequences
     * @return array<int, array<string, mixed>>
     */
    private function snapshot(FinancialPlan $plan, ?array $sequences = null): array
    {
        $query = $plan->occurrences()->orderBy('sequence');
        if ($sequences !== null) {
            $query->whereIn('sequence', $sequences);
        }

        return $query->get()->map(fn (Occurrence $row): array => $row->getAttributes())->all();
    }
}
