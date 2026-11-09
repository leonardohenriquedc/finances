<?php

namespace Tests\Feature;

use App\Models\FinancialPlan;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\FinancialPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialDomainTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_splits_total_into_cents_and_anchors_month_end_without_drift(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $user = User::factory()->create();

        $plan = $this->service()->create($user, $this->data([
            'kind' => 'installment', 'amount' => '100.00', 'installment_count' => 3,
            'first_due_date' => '2026-01-31',
        ]));

        $this->assertSame($user->id, $plan->user_id);
        $this->assertSame(['33.34', '33.33', '33.33'], $plan->occurrences()->orderBy('sequence')->get()->pluck('amount')->all());
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $plan->occurrences()->orderBy('sequence')->get()->map(fn (Occurrence $row): string => $row->due_date->toDateString())->all());
    }

    public function test_per_installment_amount_and_all_360_installments_ignore_recurring_horizon(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));

        $plan = $this->create(['kind' => 'installment', 'installment_count' => 360, 'amount_mode' => 'per_installment', 'amount' => '12.34']);

        $this->assertSame(360, $plan->occurrences()->count());
        $this->assertSame('12.34', $plan->occurrences()->where('sequence', 360)->firstOrFail()->amount);
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function interestCases(): array
    {
        return [
            'simple monthly fractional' => ['simple', 'monthly', '10.0000', '2026-01-16', '105.00'],
            'compound monthly full' => ['compound', 'monthly', '10.0000', '2026-03-02', '121.00'],
            'compound monthly fractional' => ['compound', 'monthly', '21.0000', '2026-01-16', '110.00'],
            'simple yearly' => ['simple', 'yearly', '10.0000', '2027-01-01', '110.00'],
            'compound daily' => ['compound', 'daily', '10.0000', '2026-01-03', '121.00'],
            'compound yearly fractional' => ['compound', 'yearly', '10.0000', '2026-01-02', '100.03'],
            'zero rate' => ['compound', 'yearly', '0.0000', '2027-01-01', '100.00'],
            'half up' => ['simple', 'daily', '0.0050', '2026-01-02', '100.01'],
            'compound full period half up' => ['compound', 'monthly', '0.0050', '2026-01-31', '100.01'],
            'nonnegative elapsed days' => ['simple', 'daily', '10.0000', '2025-12-01', '100.00'],
            'no interest' => ['none', 'daily', '99.0000', '2027-01-01', '100.00'],
        ];
    }

    #[DataProvider('interestCases')]
    public function test_calculates_interest_without_binary_money_arithmetic(string $type, string $period, string $rate, string $due, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));

        $plan = $this->create(['kind' => 'installment', 'installment_count' => 1, 'interest_type' => $type, 'interest_period' => $period, 'interest_rate' => $rate, 'first_due_date' => $due]);

        $this->assertSame($expected, $plan->occurrences()->firstOrFail()->amount);
    }

    public function test_preserves_large_money_values_exactly_after_database_round_trip(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));

        $plan = $this->create(['amount' => '9999999999999.99']);

        $this->assertSame('9999999999999.99', $plan->amount);
        $this->assertSame('9999999999999.99', $plan->occurrences()->firstOrFail()->amount);
    }

    public function test_recurrence_obeys_horizon_limit_end_date_and_leap_year_anchoring(): void
    {
        $this->travelTo(CarbonImmutable::parse('2024-02-01'));
        config(['finance.projection_months' => 60]);

        $plan = $this->create(['kind' => 'recurring', 'frequency' => 'yearly', 'first_due_date' => '2024-02-29', 'competence_date' => '2024-02-01', 'occurrence_limit' => 5, 'end_date' => '2028-02-29']);

        $this->assertSame(['2024-02-29', '2025-02-28', '2026-02-28', '2027-02-28', '2028-02-29'], $plan->occurrences()->orderBy('sequence')->get()->map(fn (Occurrence $row): string => $row->due_date->toDateString())->all());
        $this->assertSame('2028-02-01', $plan->occurrences()->where('sequence', 5)->firstOrFail()->competence_date->toDateString());
    }

    public function test_recurring_future_start_beyond_finite_horizon_generates_nothing_until_command_extends_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'recurring', 'first_due_date' => '2027-02-01', 'occurrence_limit' => 2]);
        $this->assertSame(0, $plan->occurrences()->count());

        $this->travelTo(CarbonImmutable::parse('2026-03-01'));
        $this->artisan('finance:generate-occurrences')->assertSuccessful();
        $this->artisan('finance:generate-occurrences')->assertSuccessful();

        $this->assertSame(2, $plan->occurrences()->count());
    }

    public function test_end_date_is_inclusive_and_weekly_schedule_is_supported(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));

        $plan = $this->create(['kind' => 'recurring', 'frequency' => 'weekly', 'end_date' => '2026-01-15']);

        $this->assertSame(['2026-01-01', '2026-01-08', '2026-01-15'], $plan->occurrences()->orderBy('sequence')->get()->map(fn (Occurrence $row): string => $row->due_date->toDateString())->all());
    }

    public function test_all_edit_preserves_paid_overridden_and_deleted_rows_while_regenerating_pending_shape(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'installment', 'installment_count' => 5, 'amount_mode' => 'per_installment']);
        $rows = $plan->occurrences()->orderBy('sequence')->get();
        $rows[0]->update(['paid_at' => '2026-01-01', 'paid_amount' => '99.00']);
        $rows[1]->update(['is_override' => true, 'amount' => '7.00']);
        $rows[2]->update(['is_deleted' => true]);

        $this->service()->update($plan, ['amount' => '200.00', 'frequency' => 'weekly', 'installment_count' => 4]);
        $this->service()->generate($plan);

        $this->assertSame(4, $plan->occurrences()->count());
        $this->assertSame('100.00', $rows[0]->fresh()->amount);
        $this->assertSame('99.00', $rows[0]->fresh()->paid_amount);
        $this->assertSame('7.00', $rows[1]->fresh()->amount);
        $this->assertSame(true, $rows[2]->fresh()->is_deleted);
        $this->assertSame('200.00', $plan->occurrences()->where('sequence', 4)->firstOrFail()->amount);
        $this->assertSame('2026-01-22', $plan->occurrences()->where('sequence', 4)->firstOrFail()->due_date->toDateString());
    }

    public function test_future_edit_includes_anchor_but_leaves_earlier_occurrences_unchanged(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'installment', 'installment_count' => 3, 'amount_mode' => 'per_installment']);
        $anchor = $plan->occurrences()->where('sequence', 2)->firstOrFail();

        $this->service()->update($plan, ['amount' => '120.00', 'category' => 'Updated'], 'future', $anchor);
        $this->service()->generate($plan);

        $this->assertSame(['100.00', '120.00', '120.00'], $plan->occurrences()->orderBy('sequence')->get()->pluck('amount')->all());
        $this->assertSame(['General', 'Updated', 'Updated'], $plan->occurrences()->orderBy('sequence')->pluck('category')->all());
    }

    public function test_future_edit_rejects_foreign_anchor_and_rolls_back_plan(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create();
        $foreign = Occurrence::factory()->create();

        try {
            $this->service()->update($plan, ['amount' => '200.00'], 'future', $foreign);
            $this->fail('A foreign anchor must be rejected.');
        } catch (ValidationException) {
            $this->assertSame('100.00', $plan->fresh()->amount);
            $this->assertSame('100.00', $plan->occurrences()->firstOrFail()->amount);
        }
    }

    public function test_pause_preserves_overdue_paid_and_tombstones_and_resume_skips_paused_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'recurring', 'frequency' => 'daily', 'occurrence_limit' => 8]);
        $plan->occurrences()->where('sequence', 8)->firstOrFail()->update(['paid_at' => '2026-01-01', 'paid_amount' => '100.00']);
        $plan->occurrences()->where('sequence', 7)->firstOrFail()->update(['is_deleted' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-01-03'));

        $this->service()->transition($plan, 'pause');
        $this->service()->generate($plan);
        $this->assertSame([1, 2, 7, 8], $plan->occurrences()->orderBy('sequence')->pluck('sequence')->all());
        $this->travelTo(CarbonImmutable::parse('2026-01-06'));
        $this->service()->transition($plan, 'resume');
        $this->service()->generate($plan);

        $this->assertSame([1, 2, 6, 7, 8], $plan->occurrences()->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame('active', $plan->fresh()->state);
        $this->assertSame(true, $plan->occurrences()->where('sequence', 7)->firstOrFail()->is_deleted);
    }

    public function test_end_keeps_overdue_and_paid_history_and_cannot_resume(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'recurring', 'frequency' => 'daily', 'occurrence_limit' => 4]);
        $plan->occurrences()->where('sequence', 4)->firstOrFail()->update(['paid_at' => '2026-01-01', 'paid_amount' => '100.00']);
        $this->travelTo(CarbonImmutable::parse('2026-01-02'));

        $this->service()->transition($plan, 'end');
        $this->service()->generate($plan);

        $this->assertSame([1, 4], $plan->occurrences()->orderBy('sequence')->pluck('sequence')->all());
        $this->expectException(ValidationException::class);
        $this->service()->transition($plan, 'resume');
    }

    public function test_delete_archives_paid_history_but_removes_unpaid_and_override_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'installment', 'installment_count' => 3]);
        $paid = $plan->occurrences()->where('sequence', 1)->firstOrFail();
        $paid->update(['paid_at' => '2026-01-01', 'paid_amount' => '33.34']);
        $plan->occurrences()->where('sequence', 2)->firstOrFail()->update(['is_override' => true]);

        $this->service()->delete($plan);

        $this->assertSame('ended', $plan->fresh()->state);
        $this->assertSame(1, $plan->occurrences()->count());
        $this->assertModelExists($paid);
    }

    public function test_delete_physically_removes_plan_without_any_paid_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create();

        $this->service()->delete($plan);

        $this->assertModelMissing($plan);
        $this->assertDatabaseCount('occurrences', 0);
    }

    /** @return array<string, array{?string, string, string}> */
    public static function statusCases(): array
    {
        return [
            'due today is open' => [null, '2026-01-02', 'open'],
            'future is open' => [null, '2026-01-03', 'open'],
            'past is overdue' => [null, '2026-01-01', 'overdue'],
            'paid on time' => ['2026-01-02', '2026-01-02', 'paid'],
            'paid early' => ['2026-01-01', '2026-01-02', 'paid'],
            'paid late' => ['2026-01-03', '2026-01-02', 'paid_late'],
        ];
    }

    #[DataProvider('statusCases')]
    public function test_computes_and_appends_status_from_calendar_dates(?string $paidAt, string $dueDate, string $expected): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-02 23:59:59'));
        $occurrence = Occurrence::factory()->make(['paid_at' => $paidAt, 'due_date' => $dueDate]);

        $this->assertSame($expected, $occurrence->status);
        $this->assertSame($expected, $occurrence->toArray()['status']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidCases(): array
    {
        return [
            'zero money' => [['amount' => '0.00']],
            'excess cents' => [['amount' => '1.001']],
            'too many installments' => [['kind' => 'installment', 'installment_count' => 361]],
            'too many occurrences' => [['kind' => 'recurring', 'occurrence_limit' => 10001]],
            'negative rate' => [['kind' => 'installment', 'interest_rate' => '-1.00']],
            'end before start' => [['kind' => 'recurring', 'end_date' => '2025-12-31']],
            'invalid direction' => [['direction' => 'transfer']],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_rejects_invalid_domain_values_without_persisting_any_rows(array $changes): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $user = User::factory()->create();

        try {
            $this->service()->create($user, $this->data($changes));
            $this->fail('Invalid financial input must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('financial_plans', 0);
            $this->assertDatabaseCount('occurrences', 0);
        }
    }

    public function test_interest_overflow_rolls_back_plan_and_previously_generated_occurrences(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $user = User::factory()->create();

        try {
            $this->service()->create($user, $this->data(['kind' => 'installment', 'installment_count' => 3, 'amount_mode' => 'per_installment', 'amount' => '9999999999999.99', 'interest_type' => 'simple', 'interest_rate' => '10.0000']));
            $this->fail('Overflow must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('financial_plans', 0);
            $this->assertDatabaseCount('occurrences', 0);
        }
    }

    public function test_paused_edit_preserves_overdue_history_and_applies_new_terms_after_resume(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'recurring', 'frequency' => 'daily', 'occurrence_limit' => 4]);
        $this->travelTo(CarbonImmutable::parse('2026-01-02'));
        $this->service()->transition($plan, 'pause');

        $this->service()->update($plan, ['amount' => '150.00']);
        $this->assertSame('100.00', $plan->occurrences()->where('sequence', 1)->firstOrFail()->amount);
        $this->travelTo(CarbonImmutable::parse('2026-01-03'));
        $this->service()->transition($plan, 'resume');

        $this->assertSame([1, 3, 4], $plan->occurrences()->where('is_deleted', false)->orderBy('sequence')->pluck('sequence')->all());
        $this->assertDatabaseHas('occurrences', ['financial_plan_id' => $plan->id, 'sequence' => 2, 'is_deleted' => true, 'amount' => '100.00']);
        $this->assertSame('150.00', $plan->occurrences()->where('sequence', 3)->firstOrFail()->amount);
    }

    public function test_ended_plan_rejects_edits_without_changing_paid_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create();
        $row = $plan->occurrences()->firstOrFail();
        $row->update(['paid_at' => '2026-01-01', 'paid_amount' => '100.00']);
        $this->service()->transition($plan, 'end');

        try {
            $this->service()->update($plan, ['amount' => '200.00']);
            $this->fail('Ended plans must reject edits.');
        } catch (ValidationException) {
            $this->assertSame('100.00', $plan->fresh()->amount);
            $this->assertSame('100.00', $row->fresh()->amount);
        }
    }

    public function test_creation_uses_explicit_owner_and_active_state_not_supplied_system_fields(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $user = User::factory()->create();
        $foreign = User::factory()->create();

        $plan = $this->service()->create($user, $this->data(['user_id' => $foreign->id, 'state' => 'ended', 'direction' => 'credit', 'projection_resume_date' => '2099-01-01']));

        $this->assertSame($user->id, $plan->user_id);
        $this->assertSame('active', $plan->state);
        $this->assertSame(null, $plan->projection_resume_date);
        $this->assertSame('credit', $plan->occurrences()->firstOrFail()->direction);
        $this->assertSame($user->id, $plan->occurrences()->firstOrFail()->user_id);
    }

    public function test_invalid_scope_does_not_change_plan_or_pending_occurrences(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create();

        try {
            $this->service()->update($plan, ['amount' => '200.00'], 'one');
            $this->fail('Single edits must use the occurrence endpoint.');
        } catch (ValidationException) {
            $this->assertSame('100.00', $plan->fresh()->amount);
            $this->assertSame('100.00', $plan->occurrences()->firstOrFail()->amount);
        }
    }

    public function test_large_daily_compound_interest_fails_atomically_instead_of_overflowing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $user = User::factory()->create();

        try {
            $this->service()->create($user, $this->data(['kind' => 'installment', 'installment_count' => 1, 'interest_type' => 'compound', 'interest_period' => 'daily', 'interest_rate' => '9999.9999', 'first_due_date' => '2027-01-01']));
            $this->fail('Compound overflow must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('financial_plans', 0);
            $this->assertDatabaseCount('occurrences', 0);
        }
    }

    public function test_single_credit_beyond_recurring_horizon_still_generates_its_one_occurrence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));

        $plan = $this->create(['direction' => 'credit', 'first_due_date' => '2030-01-01']);
        $this->service()->generate($plan);

        $this->assertSame(1, $plan->occurrences()->count());
        $this->assertSame('credit', $plan->occurrences()->firstOrFail()->direction);
    }

    /** @return array<string, array{string, string}> */
    public static function nonInstallmentKinds(): array
    {
        return ['single' => ['single', 'total'], 'recurring' => ['recurring', 'per_installment']];
    }

    #[DataProvider('nonInstallmentKinds')]
    public function test_normalizes_non_installment_terms_on_create_and_update(string $kind, string $mode): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-12-31'));
        $terms = ['kind' => $kind, 'installment_count' => 5, 'amount_mode' => 'total',
            'interest_type' => 'compound', 'interest_rate' => '10.0000', 'interest_period' => 'daily',
            'end_date' => '2026-02-01', 'occurrence_limit' => 2];

        $created = $this->create($terms);
        $plan = $this->create(['kind' => 'installment', 'installment_count' => 3]);
        $updated = $this->service()->update($plan, $terms);

        foreach ([$created, $updated] as $result) {
            $this->assertSame(1, $result->installment_count);
            $this->assertSame($mode, $result->amount_mode);
            $this->assertSame('none', $result->interest_type);
            $this->assertSame('0.0000', $result->interest_rate);
            $this->assertSame('monthly', $result->interest_period);
            $this->assertSame('100.00', $result->occurrences()->firstOrFail()->amount);
        }
        if ($kind === 'single') {
            $this->assertSame(null, $updated->end_date);
            $this->assertSame(null, $updated->occurrence_limit);
            $this->assertSame(1, $updated->occurrences()->count());
        } else {
            $this->assertSame('2026-02-01', $updated->end_date->toDateString());
            $this->assertSame(2, $updated->occurrence_limit);
            $this->assertSame(2, $updated->occurrences()->count());
        }
    }

    public function test_irrelevant_nullable_fields_do_not_block_single_or_recurring_plans(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $nullable = ['installment_count' => null, 'amount_mode' => null, 'interest_type' => null,
            'interest_rate' => null, 'interest_period' => null];

        $single = $this->create($nullable);
        $recurring = $this->create(['kind' => 'recurring', 'occurrence_limit' => 2, ...$nullable]);

        $this->assertSame(1, $single->installment_count);
        $this->assertSame('none', $single->interest_type);
        $this->assertSame('per_installment', $recurring->amount_mode);
        $this->assertSame('0.0000', $recurring->interest_rate);
        $this->assertSame(2, $recurring->occurrences()->count());
    }

    public function test_rejects_kind_changes_with_payments_without_mutating_rows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'installment', 'installment_count' => 2]);
        $paid = $plan->occurrences()->where('sequence', 1)->firstOrFail();
        $paid->update(['paid_at' => '2026-01-01', 'paid_amount' => '50.00']);
        $anchor = $plan->occurrences()->where('sequence', 2)->firstOrFail();

        try {
            $this->service()->update($plan, ['kind' => 'recurring'], 'future', $anchor);
            $this->fail('Type changes with payments must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Não é possível alterar o tipo de um planejamento com pagamentos registrados.'], $exception->errors()['kind']);
            $this->assertSame('installment', $plan->fresh()->kind);
            $this->assertSame('50.00', $paid->fresh()->paid_amount);
            $this->assertModelExists($anchor);
        }
    }

    public function test_future_edit_freezes_earlier_missing_rows_with_previous_terms(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'recurring', 'frequency' => 'daily', 'occurrence_limit' => 4]);
        $plan->occurrences()->where('sequence', 2)->delete();
        $anchor = $plan->occurrences()->where('sequence', 3)->firstOrFail();

        $this->service()->update($plan, ['amount' => '200.00', 'frequency' => 'weekly'], 'future', $anchor);
        $this->service()->generate($plan);
        $this->service()->generate($plan);

        $this->assertSame(['100.00', '100.00', '200.00', '200.00'], $plan->occurrences()->orderBy('sequence')->get()->pluck('amount')->all());
        $this->assertSame('2026-01-02', $plan->occurrences()->where('sequence', 2)->firstOrFail()->due_date->toDateString());
        $this->assertSame('2026-01-15', $plan->occurrences()->where('sequence', 3)->firstOrFail()->due_date->toDateString());
    }

    public function test_future_edit_freezes_paused_gaps_even_when_new_dates_cross_resume_floor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        $plan = $this->create(['kind' => 'recurring', 'frequency' => 'daily', 'occurrence_limit' => 8]);
        $this->travelTo(CarbonImmutable::parse('2026-01-03'));
        $this->service()->transition($plan, 'pause');
        $this->travelTo(CarbonImmutable::parse('2026-01-06'));
        $this->service()->transition($plan, 'resume');
        $anchor = $plan->occurrences()->where('sequence', 7)->firstOrFail();

        $this->service()->update($plan, ['amount' => '200.00', 'first_due_date' => '2026-01-05'], 'future', $anchor);
        $this->service()->generate($plan);

        $this->assertSame([1, 2, 6, 7, 8], $plan->occurrences()->where('is_deleted', false)->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame([3, 4, 5], $plan->occurrences()->where('is_deleted', true)->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame('100.00', $plan->occurrences()->where('sequence', 6)->firstOrFail()->amount);
        $this->assertSame('200.00', $plan->occurrences()->where('sequence', 7)->firstOrFail()->amount);
    }

    public function test_retains_explicit_lifetime_safety_ceiling_for_unlimited_daily_recurrence(): void
    {
        $start = CarbonImmutable::parse('1990-01-01');
        $last = $start->addDays(9999);
        $this->travelTo($last);
        $plan = FinancialPlan::factory()->recurring()->create(['frequency' => 'daily', 'first_due_date' => $start->toDateString()]);
        $plan->projection_resume_date = $last;
        $plan->save();

        $this->service()->generate($plan);
        $this->travelTo($last->addDays(1));
        $this->service()->generate($plan);

        $this->assertSame([10000], $plan->occurrences()->pluck('sequence')->all());
        $this->assertSame('active', $plan->fresh()->state);
    }

    public function test_custom_validator_messages_are_portuguese_independently_of_locale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01'));
        app()->setLocale('en');
        $user = User::factory()->create();

        try {
            $this->service()->create($user, $this->data(['description' => '', 'amount' => '0.00', 'direction' => 'transfer', 'first_due_date' => 'invalid']));
            $this->fail('Invalid data must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['O campo descrição é obrigatório.'], $exception->errors()['description']);
            $this->assertSame(['O campo valor deve ser no mínimo 0.01.'], $exception->errors()['amount']);
            $this->assertSame(['O valor selecionado para direção é inválido.'], $exception->errors()['direction']);
            $this->assertSame(['O campo primeiro vencimento deve estar no formato AAAA-MM-DD.'], $exception->errors()['first_due_date']);
        }
    }

    private function service(): FinancialPlanService
    {
        return app(FinancialPlanService::class);
    }

    /** @param array<string, mixed> $changes */
    private function create(array $changes = []): FinancialPlan
    {
        return $this->service()->create(User::factory()->create(), $this->data($changes));
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function data(array $changes = []): array
    {
        return array_replace(FinancialPlan::factory()->make()->only([
            'description', 'category', 'direction', 'kind', 'amount', 'amount_mode', 'installment_count',
            'frequency', 'interest_type', 'interest_rate', 'interest_period', 'end_date', 'occurrence_limit',
        ]), ['competence_date' => '2026-01-01', 'first_due_date' => '2026-01-01'], $changes);
    }
}
