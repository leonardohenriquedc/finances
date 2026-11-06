<?php

namespace Tests\Feature;

use App\Models\FinancialPlan;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\FinancialPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialPriorityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-01-15 12:00:00'));
    }

    public function test_priority_migration_rolls_back_and_backfills_existing_rows_without_changing_other_fields(): void
    {
        $user = User::factory()->create();
        foreach (['debit', 'credit'] as $direction) {
            $plan = FinancialPlan::factory()->for($user)->create([
                'direction' => $direction, 'priority' => $direction === 'debit' ? 'essential' : null,
            ]);
            Occurrence::factory()->for($plan, 'financialPlan')->for($user)->paid()->create([
                'direction' => $direction, 'priority' => $plan->priority,
                'due_date' => '2026-01-05', 'paid_at' => '2026-01-10', 'paid_amount' => '93.75',
                'is_override' => true,
            ]);
        }
        $snapshot = fn (string $table): array => DB::table($table)->orderBy('id')->get()
            ->map(fn (object $row): array => collect((array) $row)->except('priority')->all())->all();
        $before = [
            'financial_plans' => $snapshot('financial_plans'),
            'occurrences' => $snapshot('occurrences'),
        ];
        $migration = require database_path('migrations/2026_11_05_235442_add_priority_to_financial_plans_and_occurrences.php');
        $this->travelTo(CarbonImmutable::parse('2026-01-16 12:00:00'));

        $migration->down();

        foreach ($before as $table => $rows) {
            $this->assertFalse(Schema::hasColumn($table, 'priority'));
            $this->assertSame($rows, $snapshot($table), $table.' after rollback');
        }

        $migration->up();

        foreach ($before as $table => $rows) {
            $this->assertTrue(Schema::hasColumn($table, 'priority'));
            $this->assertDatabaseHas($table, ['direction' => 'debit', 'priority' => 'when_possible']);
            $this->assertDatabaseHas($table, ['direction' => 'credit', 'priority' => null]);
            $this->assertSame($rows, $snapshot($table), $table.' after upgrade');
        }
    }

    public function test_explicit_null_priority_on_debit_creation_defaults_plan_and_projection_to_when_possible(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/plans', $this->payload(['priority' => null]))->assertCreated()
            ->assertJsonPath('data.priority', 'when_possible');

        $this->assertDatabaseHas('financial_plans', ['direction' => 'debit', 'priority' => 'when_possible']);
        $this->assertDatabaseHas('occurrences', ['direction' => 'debit', 'priority' => 'when_possible']);
    }

    public function test_explicit_null_priority_on_debit_occurrence_patch_restores_default_without_changing_plan_priority(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['priority' => 'essential']);
        $row = $plan->occurrences()->sole();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$row->id, ['priority' => null])->assertOk()
            ->assertJsonPath('data.priority', 'when_possible')->assertJsonPath('data.is_override', true);

        $this->assertDatabaseHas('occurrences', ['id' => $row->id, 'priority' => 'when_possible', 'is_override' => true]);
        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'priority' => 'essential']);
    }

    public function test_nonpriority_occurrence_patch_preserves_existing_priority(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['priority' => 'essential']);
        $row = $plan->occurrences()->sole();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$row->id, ['description' => 'Adjusted rent'])->assertOk()
            ->assertJsonPath('data.priority', 'essential')->assertJsonPath('data.is_override', true);

        $this->assertDatabaseHas('occurrences', [
            'id' => $row->id, 'description' => 'Adjusted rent', 'priority' => 'essential', 'is_override' => true,
        ]);
        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'priority' => 'essential', 'description' => 'Aluguel']);
    }

    /** @return array<string, array{string, string, int}> */
    public static function outgoingPriorities(): array
    {
        $cases = [];
        foreach (['single' => 1, 'installment' => 3, 'recurring' => 3] as $kind => $count) {
            foreach (['when_possible', 'soon', 'essential'] as $priority) {
                $cases[$kind.' '.$priority] = [$kind, $priority, $count];
            }
        }

        return $cases;
    }

    #[DataProvider('outgoingPriorities')]
    public function test_debit_creation_copies_priority_to_every_projection_and_resource(string $kind, string $priority, int $count): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/api/v1/plans', $this->payload([
            'kind' => $kind, 'priority' => $priority, 'installment_count' => 3, 'occurrence_limit' => 3,
        ]));

        $response->assertCreated()->assertJsonPath('data.priority', $priority);
        $plan = FinancialPlan::query()->sole();
        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'priority' => $priority]);
        $this->assertSame(array_fill(0, $count, $priority), $plan->occurrences()->orderBy('sequence')->pluck('priority')->all());
        $this->getJson('/api/v1/plans/'.$plan->id)->assertOk()->assertJsonPath('data.priority', $priority);
        $this->getJson('/api/v1/plans')->assertOk()->assertJsonPath('data.0.priority', $priority);
        $this->getJson('/api/v1/occurrences')->assertOk()->assertJsonCount($count, 'data')
            ->assertJsonPath('data.0.priority', $priority)->assertJsonPath('data.0.plan.priority', $priority);
    }

    public function test_legacy_debit_creation_without_priority_defaults_plan_and_occurrence_to_when_possible(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/plans', $this->payload())->assertCreated()
            ->assertJsonPath('data.priority', 'when_possible');

        $this->assertDatabaseHas('financial_plans', ['priority' => 'when_possible']);
        $this->assertDatabaseHas('occurrences', ['priority' => 'when_possible']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function creditPriorities(): array
    {
        return [
            'omitted' => [[]], 'explicit null' => [['priority' => null]],
            'when possible' => [['priority' => 'when_possible']],
            'soon' => [['priority' => 'soon']], 'essential' => [['priority' => 'essential']],
        ];
    }

    #[DataProvider('creditPriorities')]
    public function test_credit_creation_normalizes_plan_and_projection_priority_to_null(array $changes): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/plans', $this->payload(['direction' => 'credit', ...$changes]))
            ->assertCreated()->assertJsonStructure(['data' => ['priority']])->assertJsonPath('data.priority', null);

        $this->assertDatabaseHas('financial_plans', ['direction' => 'credit', 'priority' => null]);
        $this->assertDatabaseHas('occurrences', ['direction' => 'credit', 'priority' => null]);
        $this->getJson('/api/v1/occurrences')->assertOk()->assertJsonStructure(['data' => [['priority', 'plan' => ['priority']]]])
            ->assertJsonPath('data.0.priority', null)->assertJsonPath('data.0.plan.priority', null);
    }

    public function test_changing_a_debit_plan_to_credit_clears_priority_from_unpaid_projections(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['priority' => 'essential']);
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['direction' => 'credit', 'priority' => 'soon'])
            ->assertOk()->assertJsonPath('data.priority', null);

        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'direction' => 'credit', 'priority' => null]);
        $this->assertDatabaseHas('occurrences', ['financial_plan_id' => $plan->id, 'direction' => 'credit', 'priority' => null]);
    }

    public function test_partial_plan_edit_without_priority_preserves_the_existing_priority(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['priority' => 'essential']);
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['description' => 'Revised rent'])
            ->assertOk()->assertJsonPath('data.priority', 'essential');

        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'priority' => 'essential', 'description' => 'Revised rent']);
        $this->assertDatabaseHas('occurrences', ['financial_plan_id' => $plan->id, 'priority' => 'essential', 'description' => 'Revised rent']);
    }

    public function test_invalid_priority_on_plan_creation_returns_422_without_persisting_records(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/plans', $this->payload(['priority' => 'urgent']))
            ->assertUnprocessable()->assertJsonValidationErrors('priority');

        $this->assertDatabaseCount('financial_plans', 0);
        $this->assertDatabaseCount('occurrences', 0);
    }

    /** @return array<string, array{string}> */
    public static function invalidPriorityMutations(): array
    {
        return ['plan' => ['plan'], 'occurrence' => ['occurrence']];
    }

    #[DataProvider('invalidPriorityMutations')]
    public function test_invalid_priority_update_returns_422_without_changing_plan_or_occurrence(string $target): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['priority' => 'soon']);
        $occurrence = $plan->occurrences()->sole();
        $planBefore = $plan->fresh()->getAttributes();
        $occurrenceBefore = $occurrence->getAttributes();
        $this->actingAs($user);
        $method = $target === 'plan' ? 'PUT' : 'PATCH';
        $path = $target === 'plan' ? '/plans/'.$plan->id : '/occurrences/'.$occurrence->id;

        $this->json($method, '/api/v1'.$path, ['priority' => 'urgent'])
            ->assertUnprocessable()->assertJsonValidationErrors('priority');

        $this->assertSame($planBefore, $plan->fresh()->getAttributes());
        $this->assertSame($occurrenceBefore, $occurrence->fresh()->getAttributes());
    }

    /** @return array<string, array{string}> */
    public static function filteredEndpoints(): array
    {
        return ['list' => ['/occurrences'], 'dashboard' => ['/dashboard'], 'export' => ['/occurrences/export']];
    }

    #[DataProvider('filteredEndpoints')]
    public function test_invalid_priority_filter_returns_422(string $path): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1'.$path.'?priority=urgent')->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    /** @return array<string, array{string}> */
    public static function editScopes(): array
    {
        return ['all' => ['all'], 'future' => ['future']];
    }

    #[DataProvider('editScopes')]
    public function test_priority_only_plan_edit_respects_scope_and_preserves_payments_and_individual_overrides(string $scope): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'installment', 'installment_count' => 5, 'amount' => '500.00', 'priority' => 'when_possible']);
        [$earlier, $anchor, $paid, $override] = $plan->occurrences()->orderBy('sequence')->get()->all();
        $this->actingAs($user);
        $this->putJson('/api/v1/occurrences/'.$paid->id.'/payment', ['paid_at' => '2026-01-10', 'paid_amount' => '93.75'])->assertOk();
        $this->patchJson('/api/v1/occurrences/'.$override->id, ['priority' => 'soon'])->assertOk();
        $paidBefore = $paid->fresh()->getAttributes();
        $overrideBefore = $override->fresh()->getAttributes();
        $earlierBefore = $earlier->getAttributes();

        $this->putJson('/api/v1/plans/'.$plan->id, [
            'priority' => 'essential', 'scope' => $scope,
            ...($scope === 'future' ? ['occurrence_id' => $anchor->id] : []),
        ])->assertOk()->assertJsonPath('data.priority', 'essential')->assertJsonPath('data.amount', '500.00');

        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'priority' => 'essential']);
        $this->assertSame($paidBefore, $paid->fresh()->getAttributes());
        $this->assertSame($overrideBefore, $override->fresh()->getAttributes());
        $this->assertSame(
            [$scope === 'future' ? 'when_possible' : 'essential', 'essential', 'when_possible', 'soon', 'essential'],
            $plan->occurrences()->orderBy('sequence')->pluck('priority')->all(),
        );
        $this->assertSame(array_fill(0, 5, '100.00'), $plan->occurrences()->orderBy('sequence')->pluck('amount')->all());
        if ($scope === 'future') {
            $this->assertSame($earlierBefore, $earlier->fresh()->getAttributes());
        }
    }

    #[DataProvider('editScopes')]
    public function test_later_recurring_generation_uses_latest_priority_without_rewriting_history(string $scope): void
    {
        config(['finance.projection_months' => 1]);
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'recurring', 'occurrence_limit' => 4, 'priority' => 'when_possible']);
        [$paid, $override] = $plan->occurrences()->orderBy('sequence')->get()->all();
        $this->actingAs($user);
        $this->putJson('/api/v1/occurrences/'.$paid->id.'/payment', ['paid_at' => '2026-01-10', 'paid_amount' => '90.00'])->assertOk();
        $this->patchJson('/api/v1/occurrences/'.$override->id, ['priority' => 'soon'])->assertOk();
        $paidBefore = $paid->fresh()->getAttributes();
        $overrideBefore = $override->fresh()->getAttributes();
        $this->putJson('/api/v1/plans/'.$plan->id, [
            'priority' => 'essential', 'scope' => $scope,
            ...($scope === 'future' ? ['occurrence_id' => $override->id] : []),
        ])->assertOk()->assertJsonPath('data.priority', 'essential');
        $this->travelTo(CarbonImmutable::parse('2026-02-16 12:00:00'));

        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);

        $this->assertSame($paidBefore, $paid->fresh()->getAttributes());
        $this->assertSame($overrideBefore, $override->fresh()->getAttributes());
        $this->assertSame(3, $plan->occurrences()->count());
        $this->assertDatabaseHas('occurrences', [
            'financial_plan_id' => $plan->id, 'sequence' => 3, 'priority' => 'essential',
            'amount' => '100.00', 'is_override' => false,
        ]);
    }

    public function test_priority_only_occurrence_patch_marks_only_that_row_as_an_override(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'installment', 'installment_count' => 3, 'priority' => 'when_possible']);
        [$first, $target, $last] = $plan->occurrences()->orderBy('sequence')->get()->all();
        $firstBefore = $first->getAttributes();
        $lastBefore = $last->getAttributes();
        $planBefore = $plan->fresh()->getAttributes();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$target->id, ['priority' => 'essential'])
            ->assertOk()->assertJsonPath('data.priority', 'essential')->assertJsonPath('data.is_override', true)
            ->assertJsonPath('data.plan.priority', 'when_possible');

        $this->assertDatabaseHas('occurrences', ['id' => $target->id, 'priority' => 'essential', 'is_override' => true, 'paid_amount' => null]);
        $this->assertSame($firstBefore, $first->fresh()->getAttributes());
        $this->assertSame($lastBefore, $last->fresh()->getAttributes());
        $this->assertSame($planBefore, $plan->fresh()->getAttributes());
    }

    public function test_credit_occurrence_patch_normalizes_priority_to_null(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['direction' => 'credit']);
        $row = $plan->occurrences()->sole();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$row->id, ['priority' => 'essential'])
            ->assertOk()->assertJsonStructure(['data' => ['priority']])
            ->assertJsonPath('data.priority', null)->assertJsonPath('data.is_override', true);

        $this->assertDatabaseHas('occurrences', ['id' => $row->id, 'priority' => null, 'is_override' => true]);
        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'priority' => null]);
    }

    public function test_paid_occurrence_priority_patch_returns_422_and_preserves_payment_and_priority(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['priority' => 'soon']);
        $row = $plan->occurrences()->sole();
        $this->actingAs($user);
        $this->putJson('/api/v1/occurrences/'.$row->id.'/payment', ['paid_at' => '2026-01-10', 'paid_amount' => '93.75'])->assertOk();
        $before = $row->fresh()->getAttributes();

        $this->patchJson('/api/v1/occurrences/'.$row->id, ['priority' => 'essential'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame($before, $row->fresh()->getAttributes());
    }

    #[DataProvider('invalidPriorityMutations')]
    public function test_priority_mutation_of_another_users_record_returns_404_without_changes(string $target): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, ['priority' => 'soon']);
        $row = $plan->occurrences()->sole();
        $planBefore = $plan->fresh()->getAttributes();
        $rowBefore = $row->getAttributes();
        $this->actingAs(User::factory()->create());
        $method = $target === 'plan' ? 'PUT' : 'PATCH';
        $path = $target === 'plan' ? '/plans/'.$plan->id : '/occurrences/'.$row->id;

        $this->json($method, '/api/v1'.$path, ['priority' => 'essential'])->assertNotFound();

        $this->assertSame($planBefore, $plan->fresh()->getAttributes());
        $this->assertSame($rowBefore, $row->fresh()->getAttributes());
    }

    public function test_future_priority_edit_rejects_another_users_anchor_with_422_without_changes(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['priority' => 'soon']);
        $row = $plan->occurrences()->sole();
        $foreign = Occurrence::factory()->create();
        $planBefore = $plan->fresh()->getAttributes();
        $rowBefore = $row->getAttributes();
        $foreignBefore = $foreign->fresh()->getAttributes();
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['scope' => 'future', 'occurrence_id' => $foreign->id, 'priority' => 'essential'])
            ->assertUnprocessable()->assertJsonValidationErrors('occurrence_id');

        $this->assertSame($planBefore, $plan->fresh()->getAttributes());
        $this->assertSame($rowBefore, $row->fresh()->getAttributes());
        $this->assertSame($foreignBefore, $foreign->fresh()->getAttributes());
    }

    public function test_priority_filter_combines_with_direction_year_and_status_for_list_totals_dashboard_and_export(): void
    {
        $user = User::factory()->create();
        $matching = ['priority' => 'essential', 'kind' => 'recurring', 'due_date' => '2026-01-20', 'amount' => '25.00'];
        $first = $this->occurrence($user, [...$matching, 'description' => 'Priority Alpha']);
        $second = $this->occurrence($user, [...$matching, 'description' => 'Priority Beta', 'amount' => '40.00', 'due_date' => '2026-01-21']);
        $excluded = [];
        foreach ([
            'Wrong priority' => ['priority' => 'soon'],
            'Wrong direction' => ['direction' => 'credit', 'priority' => null],
            'Wrong year' => ['due_date' => '2025-01-20'],
            'Wrong status' => ['paid_at' => '2026-01-10', 'paid_amount' => '999.00'],
            'Deleted priority' => ['is_deleted' => true],
        ] as $description => $difference) {
            $excluded[] = $description;
            $this->occurrence($user, array_replace($matching, ['description' => $description, 'amount' => '999.00'], $difference));
        }
        $this->occurrence(User::factory()->create(), [...$matching, 'description' => 'Foreign priority secret', 'amount' => '999.00']);
        $this->actingAs($user);
        $query = http_build_query([
            'priority' => 'essential', 'direction' => 'debit', 'year' => 2026, 'status' => 'open',
            'sort' => 'due_date', 'order' => 'asc', 'per_page' => 1,
        ]);

        $this->getJson('/api/v1/occurrences?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('meta.total', 2)->assertJsonPaths([
                'totals.debit' => '65.00', 'totals.credit' => '0.00', 'totals.projected_balance' => '-65.00',
                'totals.pending' => '65.00', 'totals.overdue' => '0.00', 'totals.realized_debit' => '0.00',
            ]);
        $this->getJson('/api/v1/occurrences?'.$query.'&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('totals.debit', '65.00');
        $dashboard = $this->getJson('/api/v1/dashboard?'.$query)->assertOk()->assertJsonPaths([
            'data.totals.debit' => '65.00', 'data.totals.credit' => '0.00', 'data.totals.pending' => '65.00',
            'data.settlement.pending' => '65.00', 'data.settlement.realized' => '0.00',
            'data.settlement.recurring_pending' => '65.00',
            'data.categories.0.total' => '65.00', 'data.categories.0.recurring_debit' => '65.00',
        ])->assertJsonCount(1, 'data.categories')->assertJsonCount(2, 'data.upcoming');
        $this->assertSame([$first->id, $second->id], array_column($dashboard->json('data.upcoming'), 'id'));
        $month = collect($dashboard->json('data.months'))->sole('month', '2026-01');
        $this->assertSame('65.00', $month['debit']);
        $this->assertSame('65.00', $month['recurring_debit']);
        $this->assertSame('65.00', $month['pending']);
        $this->assertSame('0.00', $month['realized']);
        $export = $this->get('/api/v1/occurrences/export?'.$query.'&page=2')->assertOk()->assertDownload();
        $csv = $export->streamedContent();
        $this->assertStringContainsString('Priority Alpha', $csv);
        $this->assertStringContainsString('Priority Beta', $csv);
        foreach ([...$excluded, 'Foreign priority secret'] as $description) {
            $this->assertStringNotContainsString($description, $csv);
        }
        $this->assertCount(3, preg_split('/\r\n|\n|\r/', trim($csv)));
    }

    public function test_realized_priority_filter_uses_actual_paid_amounts_and_excludes_other_priorities_and_owners(): void
    {
        $user = User::factory()->create();
        $attributes = ['priority' => 'soon', 'amount' => '100.00', 'paid_at' => '2026-01-10', 'paid_amount' => '93.75'];
        $match = $this->occurrence($user, $attributes);
        $this->occurrence($user, [...$attributes, 'priority' => 'essential', 'paid_amount' => '999.00']);
        $this->occurrence($user, ['priority' => 'soon', 'amount' => '500.00']);
        $this->occurrence(User::factory()->create(), $attributes);
        $this->actingAs($user);
        $query = 'priority=soon&direction=debit&year=2026&status=realized';

        $this->getJson('/api/v1/occurrences?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)->assertJsonPaths([
                'totals.debit' => '100.00', 'totals.realized_debit' => '93.75',
                'totals.realized_balance' => '-93.75', 'totals.pending' => '0.00',
            ]);
        $this->getJson('/api/v1/dashboard?'.$query)->assertOk()->assertJsonPaths([
            'data.totals.debit' => '100.00', 'data.totals.realized_debit' => '93.75',
            'data.settlement.realized' => '93.75', 'data.settlement.pending' => '0.00',
            'data.categories.0.total' => '100.00',
        ])->assertJsonCount(0, 'data.upcoming');
    }

    public function test_priority_filter_uses_individual_occurrence_priority_instead_of_parent_plan_priority(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['priority' => 'when_possible']);
        $row = $plan->occurrences()->sole();
        $this->actingAs($user);
        $this->patchJson('/api/v1/occurrences/'.$row->id, ['priority' => 'essential'])->assertOk();

        $this->getJson('/api/v1/occurrences?priority=essential')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $row->id)->assertJsonPath('data.0.priority', 'essential')
            ->assertJsonPath('data.0.plan.priority', 'when_possible');
        $this->getJson('/api/v1/occurrences?priority=when_possible')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('totals.debit', '0.00');
    }

    /** @return array<string, array{string, list<string>}> */
    public static function priorityOrders(): array
    {
        return [
            'ascending low to high' => ['asc', ['low early', 'low tie first', 'low tie second', 'soon', 'essential', 'credit early', 'credit late']],
            'descending high to low' => ['desc', ['essential', 'soon', 'low early', 'low tie first', 'low tie second', 'credit early', 'credit late']],
        ];
    }

    #[DataProvider('priorityOrders')]
    public function test_priority_sort_keeps_credits_last_and_breaks_ties_by_due_date_then_id_in_both_orders(string $order, array $expected): void
    {
        $user = User::factory()->create();
        $rows = [
            'credit late' => ['direction' => 'credit', 'priority' => null, 'due_date' => '2026-01-04'],
            'low tie first' => ['priority' => 'when_possible', 'due_date' => '2026-01-20'],
            'essential' => ['priority' => 'essential', 'due_date' => '2026-01-25'],
            'soon' => ['priority' => 'soon', 'due_date' => '2026-01-15'],
            'low early' => ['priority' => 'when_possible', 'due_date' => '2026-01-10'],
            'low tie second' => ['priority' => 'when_possible', 'due_date' => '2026-01-20'],
            'credit early' => ['direction' => 'credit', 'priority' => null, 'due_date' => '2026-01-01'],
        ];
        foreach ($rows as $description => $attributes) {
            $this->occurrence($user, ['description' => $description, ...$attributes]);
        }
        $this->occurrence(User::factory()->create(), ['description' => 'Foreign sorted secret', 'priority' => 'essential']);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/occurrences?sort=priority&order='.$order)->assertOk();

        $this->assertSame($expected, array_column($response->json('data'), 'description'));
        $csv = $this->get('/api/v1/occurrences/export?sort=priority&order='.$order)->assertOk()->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $descriptions = array_map(fn (string $line): ?string => str_getcsv($line, ',', '"', '')[0], array_slice($lines, 1));
        $this->assertSame($expected, $descriptions);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(array $changes = []): array
    {
        return array_replace([
            'description' => 'Aluguel', 'category' => 'Moradia', 'direction' => 'debit',
            'kind' => 'single', 'amount' => '100.00', 'competence_date' => '2026-01-01', 'first_due_date' => '2026-01-05',
        ], $changes);
    }

    /** @param array<string, mixed> $changes */
    private function plan(User $user, array $changes = []): FinancialPlan
    {
        return app(FinancialPlanService::class)->create($user, $this->payload($changes));
    }

    /** @param array<string, mixed> $changes */
    private function occurrence(User $user, array $changes): Occurrence
    {
        $attributes = array_replace([
            'description' => 'Aluguel', 'category' => 'Moradia', 'direction' => 'debit', 'kind' => 'single',
            'priority' => 'when_possible', 'amount' => '100.00', 'competence_date' => '2026-01-01', 'due_date' => '2026-01-05',
        ], $changes);
        $plan = FinancialPlan::factory()->for($user)->create(array_intersect_key($attributes, array_flip([
            'description', 'category', 'direction', 'kind', 'priority', 'amount', 'competence_date',
        ])) + ['first_due_date' => $attributes['due_date']]);

        return Occurrence::factory()->for($plan, 'financialPlan')->for($user)->create($attributes);
    }
}
