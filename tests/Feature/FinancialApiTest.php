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

class FinancialApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-01-15 12:00:00'));
    }

    /** @return array<string, array{string, string}> */
    public static function protectedEndpoints(): array
    {
        return [
            'create plan' => ['POST', '/plans'],
            'list plans' => ['GET', '/plans'],
            'show plan' => ['GET', '/plans/1'],
            'update plan' => ['PUT', '/plans/1'],
            'delete plan' => ['DELETE', '/plans/1'],
            'lifecycle' => ['POST', '/plans/1/lifecycle'],
            'list occurrences' => ['GET', '/occurrences'],
            'override occurrence' => ['PATCH', '/occurrences/1'],
            'delete occurrence' => ['DELETE', '/occurrences/1'],
            'pay occurrence' => ['PUT', '/occurrences/1/payment'],
            'reopen occurrence' => ['DELETE', '/occurrences/1/payment'],
            'export' => ['GET', '/occurrences/export'],
            'dashboard' => ['GET', '/dashboard'],
            'categories' => ['GET', '/categories'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_financial_endpoints_return_401_without_a_session(string $method, string $path): void
    {
        $this->json($method, '/api/v1'.$path)->assertUnauthorized();
        $this->assertDatabaseCount('financial_plans', 0);
        $this->assertDatabaseCount('occurrences', 0);
    }

    public function test_creates_a_plan_and_returns_decimal_amounts_and_calendar_dates(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/v1/plans', $this->payload([
            'user_id' => $other->id, 'state' => 'ended', 'id' => 99999,
        ]));

        $response->assertCreated()->assertJsonPath('data.description', 'Aluguel')
            ->assertJsonPath('data.amount', '100.00')->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.competence_date', '2026-01-01')
            ->assertJsonPath('data.first_due_date', '2026-01-05');
        $plan = FinancialPlan::query()->sole();
        $this->assertSame($user->id, $plan->user_id);
        $this->assertSame($plan->id, $response->json('data.id'));
        $this->assertDatabaseHas('occurrences', [
            'financial_plan_id' => $plan->id, 'user_id' => $user->id,
            'description' => 'Aluguel', 'amount' => '100.00', 'is_deleted' => false,
        ]);
        $this->getJson('/api/v1/plans/'.$plan->id)->assertOk()
            ->assertJsonPath('data.id', $plan->id)->assertJsonPath('data.amount', '100.00');
    }

    public function test_recurring_plan_list_is_unpaginated_and_excludes_other_kinds_and_owners(): void
    {
        $user = User::factory()->create();
        $recurring = FinancialPlan::factory()->for($user)->recurring()->count(26)->create();
        FinancialPlan::factory()->for($user)->create();
        FinancialPlan::factory()->recurring()->create();
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/plans?kind=recurring');

        $response->assertOk()->assertJsonCount(26, 'data')->assertJsonMissingPath('meta');
        $this->assertEqualsCanonicalizing($recurring->modelKeys(), array_column($response->json('data'), 'id'));
    }

    public function test_updates_a_plan_without_reassigning_ownership(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $plan = $this->plan($user);
        $this->actingAs($user);

        $response = $this->putJson('/api/v1/plans/'.$plan->id, [
            'description' => 'Aluguel revisado', 'amount' => '125.50', 'user_id' => $other->id,
        ]);

        $response->assertOk()->assertJsonPath('data.amount', '125.50')
            ->assertJsonPath('data.description', 'Aluguel revisado');
        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'user_id' => $user->id, 'amount' => '125.50']);
        $this->assertDatabaseHas('occurrences', ['financial_plan_id' => $plan->id, 'description' => 'Aluguel revisado', 'amount' => '125.50']);
    }

    public function test_delete_removes_an_unpaid_plan_and_its_occurrences(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $occurrence = $plan->occurrences()->sole();
        $this->actingAs($user);

        $this->deleteJson('/api/v1/plans/'.$plan->id)->assertSuccessful();

        $this->assertModelMissing($plan);
        $this->assertModelMissing($occurrence);
        $this->getJson('/api/v1/plans/'.$plan->id)->assertNotFound();
    }

    public function test_delete_archives_a_plan_with_payments_without_erasing_paid_history(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'installment', 'installment_count' => 2]);
        $paid = $plan->occurrences()->orderBy('sequence')->firstOrFail();
        $paid->update(['paid_at' => '2026-01-05', 'paid_amount' => '45.00']);
        $this->actingAs($user);

        $this->deleteJson('/api/v1/plans/'.$plan->id)->assertSuccessful();

        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'state' => 'ended']);
        $this->assertDatabaseHas('occurrences', ['id' => $paid->id, 'paid_amount' => '45.00']);
        $this->assertSame([$paid->id], $plan->occurrences()->pluck('id')->all());
    }

    /** @return array<string, array{string, string}> */
    public static function lifecycleActions(): array
    {
        return ['pause' => ['pause', 'paused'], 'resume' => ['resume', 'active'], 'end' => ['end', 'ended']];
    }

    #[DataProvider('lifecycleActions')]
    public function test_lifecycle_action_returns_and_persists_the_new_state(string $action, string $state): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'recurring', 'occurrence_limit' => 3]);
        if ($action === 'resume') {
            app(FinancialPlanService::class)->transition($plan, 'pause');
        }
        $this->actingAs($user);

        $this->postJson('/api/v1/plans/'.$plan->id.'/lifecycle', ['action' => $action])
            ->assertOk()->assertJsonPath('data.state', $state);

        $this->assertDatabaseHas('financial_plans', ['id' => $plan->id, 'state' => $state]);
    }

    public function test_invalid_lifecycle_action_returns_422_without_changing_the_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $this->actingAs($user);

        $this->postJson('/api/v1/plans/'.$plan->id.'/lifecycle', ['action' => 'destroy'])
            ->assertUnprocessable()->assertJsonValidationErrors('action');

        $this->assertSame('active', $plan->fresh()->state);
        $this->assertDatabaseCount('occurrences', 1);
    }

    /** @return array<string, array{string, string}> */
    public static function foreignRecordEndpoints(): array
    {
        return [
            'show plan' => ['GET', '/plans/{plan}'],
            'update plan' => ['PUT', '/plans/{plan}'],
            'delete plan' => ['DELETE', '/plans/{plan}'],
            'lifecycle' => ['POST', '/plans/{plan}/lifecycle'],
            'override' => ['PATCH', '/occurrences/{occurrence}'],
            'delete occurrence' => ['DELETE', '/occurrences/{occurrence}'],
            'pay' => ['PUT', '/occurrences/{occurrence}/payment'],
            'reopen' => ['DELETE', '/occurrences/{occurrence}/payment'],
        ];
    }

    #[DataProvider('foreignRecordEndpoints')]
    public function test_foreign_records_return_404_without_modifying_either_owners_records(string $method, string $path): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $occurrence = $plan->occurrences()->sole();
        $originalPlan = $plan->getAttributes();
        $originalOccurrence = $occurrence->getAttributes();
        $this->actingAs($owner)->getJson('/api/v1/plans/'.$plan->id)->assertOk();
        $this->actingAs(User::factory()->create());
        $path = str_replace(['{plan}', '{occurrence}'], [(string) $plan->id, (string) $occurrence->id], $path);

        $this->json($method, '/api/v1'.$path, $this->payload([
            'action' => 'pause', 'paid_at' => '2026-01-10', 'paid_amount' => '99.00',
        ]))->assertNotFound();

        $this->assertSame($originalPlan, $plan->fresh()->getAttributes());
        $this->assertSame($originalOccurrence, $occurrence->fresh()->getAttributes());
        $this->assertDatabaseCount('financial_plans', 1);
        $this->assertDatabaseCount('occurrences', 1);
    }

    public function test_paid_foreign_occurrence_cannot_be_reopened(): void
    {
        $occurrence = Occurrence::factory()->paid()->create();
        $this->actingAs($occurrence->user)->getJson('/api/v1/occurrences')->assertOk()
            ->assertJsonPath('data.0.id', $occurrence->id);
        $this->actingAs(User::factory()->create());

        $this->deleteJson('/api/v1/occurrences/'.$occurrence->id.'/payment')->assertNotFound();

        $this->assertNotNull($occurrence->fresh()->paid_at);
        $this->assertSame('100.00', $occurrence->fresh()->paid_amount);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPlanPayloads(): array
    {
        return [
            'invalid calendar date' => [['first_due_date' => '2026-02-30'], 'first_due_date'],
            'timestamp instead of date' => [['competence_date' => '2026-01-01T12:00:00Z'], 'competence_date'],
            'negative amount' => [['amount' => '-0.01'], 'amount'],
            'subcent amount' => [['amount' => '1.001'], 'amount'],
            'invalid rate' => [['interest_rate' => '-1'], 'interest_rate'],
            'excess rate precision' => [['interest_rate' => '1.00001'], 'interest_rate'],
            'invalid kind' => [['kind' => 'unknown'], 'kind'],
            'invalid direction' => [['direction' => 'transfer'], 'direction'],
            'invalid frequency' => [['frequency' => 'whenever'], 'frequency'],
            'end before start' => [['end_date' => '2026-01-04'], 'end_date'],
        ];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidPlanPayloads')]
    public function test_invalid_plan_creation_returns_422_and_persists_nothing(array $changes, string $error): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/plans', $this->payload($changes))
            ->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertDatabaseCount('financial_plans', 0);
        $this->assertDatabaseCount('occurrences', 0);
    }

    public function test_empty_plan_payload_returns_422_with_required_field_errors(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/v1/plans', [])->assertUnprocessable()->assertJsonValidationErrors([
            'description', 'category', 'direction', 'kind', 'amount', 'competence_date', 'first_due_date',
        ]);

        $this->assertDatabaseCount('financial_plans', 0);
        $this->assertDatabaseCount('occurrences', 0);
    }

    public function test_future_plan_edit_uses_the_anchor_inclusively_through_http(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'installment', 'installment_count' => 3, 'amount' => '300.00']);
        [$first, $anchor] = $plan->occurrences()->orderBy('sequence')->get()->all();
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['scope' => 'future', 'occurrence_id' => $anchor->id, 'amount' => '600.00'])
            ->assertOk()->assertJsonPath('data.amount', '600.00');

        $this->assertSame('100.00', $first->fresh()->amount);
        $this->assertSame(['100.00', '200.00', '200.00'], $plan->occurrences()->orderBy('sequence')->pluck('amount')->all());
    }

    public function test_future_plan_edit_rejects_a_foreign_anchor_with_422_without_mutation(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $foreign = Occurrence::factory()->create();
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['scope' => 'future', 'occurrence_id' => $foreign->id, 'amount' => '600.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('occurrence_id');

        $this->assertSame('100.00', $plan->fresh()->amount);
        $this->assertSame('100.00', $plan->occurrences()->sole()->amount);
        $this->assertModelExists($foreign);
    }

    public function test_invalid_plan_update_returns_422_without_changing_existing_rows(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $occurrence = $plan->occurrences()->sole();
        $this->actingAs($user);

        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame('100.00', $plan->fresh()->amount);
        $this->assertSame('100.00', $occurrence->fresh()->amount);
    }

    public function test_occurrence_resource_nests_its_plan_and_excludes_tombstones_and_other_users(): void
    {
        $user = User::factory()->create();
        $occurrence = $this->occurrence($user, ['amount' => '123.45', 'due_date' => '2026-01-15']);
        $this->occurrence($user, ['is_deleted' => true]);
        $foreign = Occurrence::factory()->create();
        $this->actingAs($user);

        $this->getJson('/api/v1/occurrences')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $occurrence->id)->assertJsonPath('data.0.amount', '123.45')
            ->assertJsonPath('data.0.due_date', '2026-01-15')
            ->assertJsonPath('data.0.competence_date', '2026-01-01')
            ->assertJsonPath('data.0.status', 'open')->assertJsonPath('data.0.plan.id', $occurrence->financial_plan_id)
            ->assertJsonMissing(['id' => $foreign->id])->assertJsonPath('meta.total', 1);
    }

    public function test_overrides_an_unpaid_occurrence_without_accepting_system_or_payment_fields(): void
    {
        $user = User::factory()->create();
        $occurrence = $this->occurrence($user);
        $foreign = Occurrence::factory()->create();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$occurrence->id, [
            'description' => 'Conta ajustada', 'category' => 'Serviços', 'amount' => '88.75', 'due_date' => '2026-01-20',
            'user_id' => $foreign->user_id, 'financial_plan_id' => $foreign->financial_plan_id,
            'paid_at' => '2026-01-10', 'paid_amount' => '1.00', 'is_deleted' => true,
        ])->assertOk()->assertJsonPath('data.amount', '88.75')
            ->assertJsonPath('data.due_date', '2026-01-20')->assertJsonPath('data.is_override', true);

        $this->assertDatabaseHas('occurrences', [
            'id' => $occurrence->id, 'user_id' => $user->id, 'financial_plan_id' => $occurrence->financial_plan_id,
            'description' => 'Conta ajustada', 'category' => 'Serviços', 'amount' => '88.75',
            'is_override' => true, 'is_deleted' => false, 'paid_at' => null, 'paid_amount' => null,
        ]);
    }

    public function test_invalid_occurrence_override_returns_422_and_preserves_the_original_values(): void
    {
        $user = User::factory()->create();
        $occurrence = $this->occurrence($user);
        $original = $occurrence->fresh()->getAttributes();
        $this->actingAs($user);

        $this->patchJson('/api/v1/occurrences/'.$occurrence->id, ['due_date' => '2026-02-30', 'amount' => '-1.00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'amount']);

        $this->assertSame($original, $occurrence->fresh()->getAttributes());
    }

    public function test_payment_and_override_survive_a_plan_edit_through_http(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, ['kind' => 'installment', 'installment_count' => 3]);
        [$paid, $override] = $plan->occurrences()->orderBy('sequence')->get()->all();
        $this->actingAs($user);
        $this->putJson('/api/v1/occurrences/'.$paid->id.'/payment', ['paid_at' => '2026-01-10', 'paid_amount' => '30.00'])->assertOk();
        $this->patchJson('/api/v1/occurrences/'.$override->id, ['amount' => '70.00', 'description' => 'Exceção'])->assertOk();

        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '600.00', 'description' => 'Novo plano'])->assertOk();

        $this->assertSame('30.00', $paid->fresh()->paid_amount);
        $this->assertSame('2026-01-10', $paid->fresh()->paid_at->toDateString());
        $this->assertSame('70.00', $override->fresh()->amount);
        $this->assertSame('Exceção', $override->fresh()->description);
        $this->assertSame('200.00', $plan->occurrences()->where('sequence', 3)->sole()->amount);
    }

    public function test_recurring_api_edits_and_scheduled_generation_preserve_paid_history_and_manual_overrides(): void
    {
        config(['finance.projection_months' => 1]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $created = $this->postJson('/api/v1/plans', $this->payload(['kind' => 'recurring', 'occurrence_limit' => 4]))->assertCreated();
        $plan = FinancialPlan::query()->findOrFail($created->json('data.id'));
        [$paid, $override] = $plan->occurrences()->orderBy('sequence')->get()->all();
        $this->putJson('/api/v1/occurrences/'.$paid->id.'/payment', ['paid_at' => '2026-01-10', 'paid_amount' => '90.00'])->assertOk();
        $this->patchJson('/api/v1/occurrences/'.$override->id, ['amount' => '130.00', 'description' => 'Manual recurring edit'])->assertOk();
        $paidBefore = $paid->fresh()->getAttributes();
        $overrideBefore = $override->fresh()->getAttributes();
        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '200.00', 'description' => 'Revised recurring plan'])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-02-16 12:00:00'));

        $this->artisan('finance:generate-occurrences')->assertExitCode(0);
        $this->artisan('finance:generate-occurrences')->assertExitCode(0);

        $this->assertSame($paidBefore, $paid->fresh()->getAttributes());
        $this->assertSame($overrideBefore, $override->fresh()->getAttributes());
        $this->assertSame(3, $plan->occurrences()->count());
        $this->assertDatabaseHas('occurrences', ['financial_plan_id' => $plan->id, 'sequence' => 3, 'amount' => '200.00', 'description' => 'Revised recurring plan']);
        $this->getJson('/api/v1/occurrences?status=paid_late')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paid->id)->assertJsonPath('data.0.amount', '100.00')
            ->assertJsonPath('data.0.paid_amount', '90.00')->assertJsonPath('data.0.paid_at', '2026-01-10');
    }

    public function test_payment_returns_late_status_and_reopening_clears_both_payment_fields(): void
    {
        $user = User::factory()->create();
        $occurrence = $this->occurrence($user, ['due_date' => '2026-01-05']);
        $this->actingAs($user);

        $this->putJson('/api/v1/occurrences/'.$occurrence->id.'/payment', ['paid_at' => '2026-01-15', 'paid_amount' => '90.25'])
            ->assertOk()->assertJsonPath('data.paid_at', '2026-01-15')
            ->assertJsonPath('data.paid_amount', '90.25')->assertJsonPath('data.status', 'paid_late');

        $this->assertSame('90.25', $occurrence->fresh()->paid_amount);
        $this->deleteJson('/api/v1/occurrences/'.$occurrence->id.'/payment')->assertSuccessful();
        $this->assertNull($occurrence->fresh()->paid_at);
        $this->assertNull($occurrence->fresh()->paid_amount);
        $this->getJson('/api/v1/occurrences?status=overdue')->assertOk()->assertJsonPath('data.0.id', $occurrence->id);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayments(): array
    {
        return [
            'future date' => [['paid_at' => '2026-01-16', 'paid_amount' => '90.00'], 'paid_at'],
            'invalid date' => [['paid_at' => '2026-02-30', 'paid_amount' => '90.00'], 'paid_at'],
            'negative amount' => [['paid_at' => '2026-01-15', 'paid_amount' => '-1.00'], 'paid_amount'],
            'subcent amount' => [['paid_at' => '2026-01-15', 'paid_amount' => '1.001'], 'paid_amount'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayments')]
    public function test_invalid_payment_returns_422_without_settling_the_occurrence(array $payload, string $error): void
    {
        $user = User::factory()->create();
        $occurrence = $this->occurrence($user);
        $this->actingAs($user);

        $this->putJson('/api/v1/occurrences/'.$occurrence->id.'/payment', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertNull($occurrence->fresh()->paid_at);
        $this->assertNull($occurrence->fresh()->paid_amount);
    }

    public function test_deleting_an_unpaid_occurrence_tombstones_it_and_hides_it_from_reads(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $occurrence = $plan->occurrences()->sole();
        $this->actingAs($user);

        $this->deleteJson('/api/v1/occurrences/'.$occurrence->id)->assertSuccessful();

        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id, 'is_deleted' => true]);
        $this->getJson('/api/v1/occurrences')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->putJson('/api/v1/plans/'.$plan->id, ['amount' => '150.00'])->assertOk();
        $this->assertDatabaseCount('occurrences', 1);
        $this->assertTrue($occurrence->fresh()->is_deleted);
    }

    /** @return array<string, array{string}> */
    public static function paidMutations(): array
    {
        return ['edit' => ['PATCH'], 'delete' => ['DELETE']];
    }

    #[DataProvider('paidMutations')]
    public function test_paid_occurrence_edit_or_delete_returns_422_and_preserves_payment(string $method): void
    {
        $user = User::factory()->create();
        $occurrence = $this->occurrence($user, ['paid_at' => '2026-01-05', 'paid_amount' => '95.00']);
        $original = $occurrence->fresh()->getAttributes();
        $this->actingAs($user);

        $this->json($method, '/api/v1/occurrences/'.$occurrence->id, ['amount' => '50.00'])->assertUnprocessable();

        $this->assertSame($original, $occurrence->fresh()->getAttributes());
    }

    public function test_combines_all_filters_using_due_dates_and_calculates_totals_before_pagination(): void
    {
        $user = User::factory()->create();
        $matching = ['description' => 'Aluguel mensal', 'category' => 'Moradia', 'direction' => 'debit', 'kind' => 'recurring', 'competence_date' => '2025-12-01'];
        $first = $this->occurrence($user, [...$matching, 'due_date' => '2026-01-10', 'amount' => '9.00']);
        $this->occurrence($user, [...$matching, 'due_date' => '2026-01-14', 'amount' => '100.00']);
        foreach ([
            ['due_date' => '2026-01-09'], ['due_date' => '2026-01-15'], ['due_date' => '2026-02-10'],
            ['due_date' => '2025-01-10'], ['category' => 'Lazer'], ['description' => 'Internet'],
            ['direction' => 'credit'], ['kind' => 'single'], ['paid_at' => '2026-01-10', 'paid_amount' => '100.00'],
        ] as $difference) {
            $this->occurrence($user, array_replace($matching, ['due_date' => '2026-01-10', 'amount' => '500.00'], $difference));
        }
        $this->occurrence(User::factory()->create(), [...$matching, 'due_date' => '2026-01-10', 'amount' => '900.00']);
        $this->actingAs($user);
        $query = http_build_query([
            'month' => 1, 'year' => 2026, 'from' => '2026-01-10', 'to' => '2026-01-14',
            'category' => 'Moradia', 'status' => 'overdue', 'text' => 'Aluguel', 'direction' => 'debit',
            'kind' => 'recurring', 'sort' => 'amount', 'order' => 'asc', 'per_page' => 1,
        ]);

        $this->getJson('/api/v1/occurrences?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPaths([
                'totals.credit' => '0.00', 'totals.debit' => '109.00', 'totals.projected_balance' => '-109.00',
                'totals.realized_credit' => '0.00', 'totals.realized_debit' => '0.00', 'totals.realized_balance' => '0.00',
                'totals.pending' => '109.00', 'totals.overdue' => '109.00',
            ]);
        $this->getJson('/api/v1/occurrences?'.$query.'&page=2')->assertOk()
            ->assertJsonPath('data.0.amount', '100.00')->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('totals.debit', '109.00');
    }

    public function test_totals_use_actual_payment_amounts_including_late_payments_and_exclude_tombstones(): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['direction' => 'credit', 'amount' => '200.00', 'paid_at' => '2026-01-10', 'paid_amount' => '180.00']);
        $this->occurrence($user, ['amount' => '100.00', 'due_date' => '2026-01-05', 'paid_at' => '2026-01-12', 'paid_amount' => '110.00']);
        $this->occurrence($user, ['amount' => '30.00', 'due_date' => '2026-01-14']);
        $this->occurrence($user, ['amount' => '40.00', 'due_date' => '2026-01-15']);
        $this->occurrence($user, ['amount' => '999.00', 'is_deleted' => true]);
        $this->occurrence(User::factory()->create(), ['amount' => '999.00']);
        $this->actingAs($user);

        $this->getJson('/api/v1/occurrences?month=1&year=2026&per_page=1')->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPaths([
                'totals.credit' => '200.00', 'totals.debit' => '170.00', 'totals.projected_balance' => '30.00',
                'totals.realized_credit' => '180.00', 'totals.realized_debit' => '110.00', 'totals.realized_balance' => '70.00',
                'totals.pending' => '70.00', 'totals.overdue' => '30.00',
            ]);
    }

    /** @return array<string, array{string, string}> */
    public static function statuses(): array
    {
        return ['open' => ['open', 'today'], 'overdue' => ['overdue', 'yesterday'], 'paid' => ['paid', 'on time'], 'paid late' => ['paid_late', 'late']];
    }

    #[DataProvider('statuses')]
    public function test_status_filter_matches_dynamic_calendar_and_payment_status(string $status, string $case): void
    {
        $user = User::factory()->create();
        $rows = [
            'today' => $this->occurrence($user, ['due_date' => '2026-01-15']),
            'yesterday' => $this->occurrence($user, ['due_date' => '2026-01-14']),
            'on time' => $this->occurrence($user, ['due_date' => '2026-01-10', 'paid_at' => '2026-01-10', 'paid_amount' => '100.00']),
            'late' => $this->occurrence($user, ['due_date' => '2026-01-10', 'paid_at' => '2026-01-11', 'paid_amount' => '95.00']),
        ];
        $this->actingAs($user);

        $this->getJson('/api/v1/occurrences?status='.$status)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rows[$case]->id)->assertJsonPath('data.0.status', $status);
    }

    /** @return array<string, array{string, list<string>, string, string, string}> */
    public static function settlementStatuses(): array
    {
        return [
            'pending includes open and overdue' => ['pending', ['open', 'overdue'], '30.00', '0.00', '20.00'],
            'realized includes paid and paid late' => ['realized', ['paid', 'paid_late'], '70.00', '75.00', '0.00'],
        ];
    }

    /** @param list<string> $statuses */
    #[DataProvider('settlementStatuses')]
    public function test_chart_status_filter_selects_both_underlying_statuses_and_matching_totals(string $status, array $statuses, string $planned, string $realized, string $overdue): void
    {
        $user = User::factory()->create();
        $rows = $this->settlementOccurrences($user);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/occurrences?status='.$status);

        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.debit', $planned)->assertJsonPath('totals.realized_debit', $realized)
            ->assertJsonPath('totals.overdue', $overdue);
        $this->assertEqualsCanonicalizing($statuses, array_column($response->json('data'), 'status'));
        $this->assertEqualsCanonicalizing(array_map(fn (string $key): int => $rows[$key]->id, $statuses), array_column($response->json('data'), 'id'));
    }

    /** @param list<string> $statuses */
    #[DataProvider('settlementStatuses')]
    public function test_dashboard_chart_status_filter_limits_aggregates_to_pending_or_realized_rows(string $status, array $statuses, string $planned, string $realized, string $overdue): void
    {
        $user = User::factory()->create();
        $this->settlementOccurrences($user);
        $this->actingAs($user);
        $pending = $status === 'pending' ? '30.00' : '0.00';

        $this->getJson('/api/v1/dashboard?status='.$status)->assertOk()
            ->assertJsonPath('data.totals.debit', $planned)->assertJsonPath('data.totals.realized_debit', $realized)
            ->assertJsonPath('data.totals.overdue', $overdue)->assertJsonPath('data.totals.pending', $pending)
            ->assertJsonPath('data.settlement.realized', $realized)->assertJsonPath('data.settlement.pending', $pending)
            ->assertJsonPath('data.categories.0.total', $planned)->assertJsonPath('data.months.0.debit', $planned);
    }

    /** @param list<string> $statuses */
    #[DataProvider('settlementStatuses')]
    public function test_csv_chart_status_filter_exports_only_the_matching_payment_group(string $status, array $statuses, string $planned, string $realized, string $overdue): void
    {
        $user = User::factory()->create();
        $this->settlementOccurrences($user);
        $this->actingAs($user);

        $response = $this->get('/api/v1/occurrences/export?status='.$status.'&per_page=1');

        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $this->assertCount(3, $lines);
        $descriptions = array_map(fn (string $line): ?string => str_getcsv($line, ',', '"', '')[0], array_slice($lines, 1));
        $this->assertEqualsCanonicalizing(array_map(fn (string $candidate): string => 'Chart '.$candidate, $statuses), $descriptions);
    }

    public function test_open_occurrence_becomes_overdue_on_the_next_calendar_day(): void
    {
        $user = User::factory()->create();
        $occurrence = $this->occurrence($user, ['due_date' => '2026-01-15']);
        $this->actingAs($user);
        $this->getJson('/api/v1/occurrences?status=open')->assertOk()->assertJsonPath('data.0.id', $occurrence->id);
        $this->travelTo(CarbonImmutable::parse('2026-01-16 00:00:00'));

        $this->getJson('/api/v1/occurrences?status=overdue')->assertOk()->assertJsonPath('data.0.id', $occurrence->id);
        $this->getJson('/api/v1/occurrences?status=open')->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array<string, array{string, string, array<string, mixed>, array<string, mixed>}> */
    public static function sorts(): array
    {
        return [
            'numeric ascending' => ['amount', 'asc', ['amount' => '9.00'], ['amount' => '100.00']],
            'numeric descending' => ['amount', 'desc', ['amount' => '100.00'], ['amount' => '9.00']],
            'due date' => ['due_date', 'asc', ['due_date' => '2026-01-02'], ['due_date' => '2026-01-20']],
            'description' => ['description', 'asc', ['description' => 'Alpha'], ['description' => 'Zulu']],
            'category' => ['category', 'desc', ['category' => 'Zulu'], ['category' => 'Alpha']],
            'status' => ['status', 'asc', ['due_date' => '2026-01-20'], ['due_date' => '2026-01-02']],
        ];
    }

    /**
     * @param  array<string, mixed>  $firstAttributes
     * @param  array<string, mixed>  $secondAttributes
     */
    #[DataProvider('sorts')]
    public function test_allowed_sort_returns_the_expected_order(string $sort, string $order, array $firstAttributes, array $secondAttributes): void
    {
        $user = User::factory()->create();
        $second = $this->occurrence($user, $secondAttributes);
        $first = $this->occurrence($user, $firstAttributes);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/occurrences?'.http_build_query(['sort' => $sort, 'order' => $order]));

        $response->assertOk();
        $this->assertSame([$first->id, $second->id], array_column($response->json('data'), 'id'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidFilters(): array
    {
        return [
            'invalid month' => [['month' => 13], 'month'],
            'invalid from' => [['from' => '2026-02-30'], 'from'],
            'reversed interval' => [['from' => '2026-01-20', 'to' => '2026-01-01'], 'to'],
            'invalid status' => [['status' => 'settled'], 'status'],
            'invalid direction' => [['direction' => 'transfer'], 'direction'],
            'invalid kind' => [['kind' => 'unknown'], 'kind'],
            'sort injection' => [['sort' => 'amount desc; DROP TABLE users; --'], 'sort'],
            'order injection' => [['order' => 'asc, (select password from users)'], 'order'],
            'invalid page size' => [['per_page' => 0], 'per_page'],
        ];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_422_instead_of_reaching_dynamic_queries(array $query, string $error): void
    {
        $user = User::factory()->create();
        $occurrence = $this->occurrence($user);
        $this->actingAs($user);

        $this->getJson('/api/v1/occurrences?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors($error);

        $this->assertModelExists($user);
        $this->assertModelExists($occurrence);
    }

    /** @return array<string, array{string}> */
    public static function aggregateEndpoints(): array
    {
        return ['csv' => ['/occurrences/export'], 'dashboard' => ['/dashboard']];
    }

    #[DataProvider('aggregateEndpoints')]
    public function test_aggregate_endpoints_reject_sort_injection_with_422(string $path): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1'.$path.'?'.http_build_query(['sort' => 'amount; DROP TABLE users; --']))
            ->assertUnprocessable()->assertJsonValidationErrors('sort');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_text_search_treats_sql_like_input_as_text_and_cannot_bypass_ownership(): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['description' => 'Ordinary']);
        $this->occurrence(User::factory()->create(), ['description' => "' OR 1=1 --"]);
        $this->actingAs($user);

        $this->getJson('/api/v1/occurrences?'.http_build_query(['text' => "' OR 1=1 --"]))
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_categories_are_unique_and_do_not_leak_another_users_categories(): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['category' => 'Moradia']);
        $this->occurrence($user, ['category' => 'Moradia']);
        $this->occurrence($user, ['category' => 'Salário']);
        $this->occurrence(User::factory()->create(), ['category' => 'Segredo']);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/categories');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(['Moradia', 'Salário'], $response->json('data'));
    }

    public function test_export_uses_all_filtered_results_not_the_requested_page_and_never_leaks_other_users(): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['description' => 'Export Alpha', 'amount' => '9.00', 'due_date' => '2026-01-10']);
        $this->occurrence($user, ['description' => 'Export Beta', 'amount' => '100.00', 'due_date' => '2026-01-14']);
        $this->occurrence($user, ['description' => 'Export Outside range', 'due_date' => '2026-02-10']);
        $this->occurrence($user, ['description' => 'Export Wrong category', 'category' => 'Lazer']);
        $this->occurrence($user, ['description' => 'Export Wrong kind', 'kind' => 'recurring']);
        $this->occurrence($user, ['description' => 'Export Wrong direction', 'direction' => 'credit']);
        $this->occurrence($user, ['description' => 'Export Paid', 'paid_at' => '2026-01-05', 'paid_amount' => '100.00']);
        $this->occurrence($user, ['description' => 'Export Deleted secret', 'is_deleted' => true]);
        $this->occurrence(User::factory()->create(), ['description' => 'Export Foreign secret']);
        $this->actingAs($user);
        $query = http_build_query(['month' => 1, 'year' => 2026, 'from' => '2026-01-05', 'to' => '2026-01-14', 'category' => 'Moradia', 'text' => 'Export', 'direction' => 'debit', 'kind' => 'single', 'status' => 'overdue', 'sort' => 'amount', 'order' => 'asc', 'per_page' => 1, 'page' => 2]);

        $response = $this->get('/api/v1/occurrences/export?'.$query);

        $response->assertOk()->assertDownload()->assertHeaderContains('Content-Type', 'text/csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Export Alpha', $csv);
        $this->assertStringContainsString('Export Beta', $csv);
        $this->assertStringNotContainsString('Outside range', $csv);
        $this->assertStringNotContainsString('Wrong category', $csv);
        $this->assertStringNotContainsString('Deleted secret', $csv);
        $this->assertStringNotContainsString('Foreign secret', $csv);
        $this->assertStringNotContainsString('Export Wrong kind', $csv);
        $this->assertStringNotContainsString('Export Wrong direction', $csv);
        $this->assertStringNotContainsString('Export Paid', $csv);
        $this->assertLessThan(strpos($csv, 'Export Beta'), strpos($csv, 'Export Alpha'));
    }

    /** @return array<string, array{string}> */
    public static function spreadsheetFormulas(): array
    {
        return ['equals' => ['=1+1'], 'plus' => ['+SUM(1,2)'], 'minus' => ['-1+2'], 'at sign' => ['@SUM(1,2)']];
    }

    #[DataProvider('spreadsheetFormulas')]
    public function test_export_escapes_formula_prefixes_in_description_and_category(string $formula): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['description' => $formula, 'category' => $formula]);
        $this->actingAs($user);

        $response = $this->get('/api/v1/occurrences/export');

        $response->assertOk();
        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $this->assertCount(2, $lines);
        $cells = str_getcsv($lines[1], ',', '"', '');
        $this->assertSame(2, count(array_filter($cells, fn (?string $cell): bool => $cell === "'".$formula)));
        $this->assertNotContains($formula, $cells);
    }

    public function test_dashboard_aggregates_actual_and_planned_money_by_due_month_and_excludes_foreign_and_deleted_rows(): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['direction' => 'credit', 'kind' => 'recurring', 'amount' => '200.00', 'paid_at' => '2026-01-05', 'paid_amount' => '180.00']);
        $this->occurrence($user, ['kind' => 'recurring', 'amount' => '100.00', 'paid_at' => '2026-01-12', 'paid_amount' => '110.00']);
        $upcoming = $this->occurrence($user, ['amount' => '40.00', 'due_date' => '2026-01-20', 'competence_date' => '2025-12-01']);
        $this->occurrence($user, ['amount' => '999.00', 'is_deleted' => true]);
        $this->occurrence(User::factory()->create(), ['amount' => '999.00', 'category' => 'Segredo']);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/dashboard?month=1&year=2026');

        $response->assertOk()->assertJsonStructure(['data' => [
            'totals', 'months' => [['month', 'credit', 'debit', 'recurring_credit', 'recurring_debit', 'realized', 'pending', 'balance', 'realized_balance']],
            'categories' => [['category', 'credit', 'debit', 'total']], 'settlement' => ['realized', 'pending'], 'upcoming', 'projection_end',
        ]])->assertJsonPath('data.totals.credit', '200.00')->assertJsonPath('data.totals.debit', '140.00')
            ->assertJsonPath('data.totals.realized_credit', '180.00')->assertJsonPath('data.totals.realized_debit', '110.00')
            ->assertJsonPath('data.totals.realized_balance', '70.00')->assertJsonPath('data.totals.pending', '40.00')
            ->assertJsonPath('data.settlement.realized', '290.00')->assertJsonPath('data.settlement.pending', '40.00')
            ->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.category', 'Moradia')
            ->assertJsonPath('data.categories.0.credit', '200.00')->assertJsonPath('data.categories.0.debit', '140.00')
            ->assertJsonPath('data.categories.0.total', '340.00')
            ->assertJsonCount(1, 'data.upcoming')->assertJsonPath('data.upcoming.0.id', $upcoming->id)
            ->assertJsonPath('data.upcoming.0.plan.id', $upcoming->financial_plan_id);
        $month = collect($response->json('data.months'))->sole('month', '2026-01');
        foreach ([
            'month' => '2026-01', 'credit' => '200.00', 'debit' => '140.00',
            'recurring_credit' => '200.00', 'recurring_debit' => '100.00',
            'realized' => '290.00', 'pending' => '40.00', 'balance' => '60.00', 'realized_balance' => '70.00',
        ] as $key => $value) {
            $this->assertSame($value, $month[$key], 'January '.$key);
        }
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $response->json('data.projection_end'));
    }

    public function test_dashboard_recurring_category_and_settlement_fields_exclude_nonrecurring_and_foreign_money(): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['kind' => 'recurring', 'direction' => 'credit', 'amount' => '200.00', 'paid_at' => '2026-01-05', 'paid_amount' => '180.00']);
        $this->occurrence($user, ['kind' => 'recurring', 'amount' => '100.00', 'paid_at' => '2026-01-10', 'paid_amount' => '110.00']);
        $this->occurrence($user, ['kind' => 'recurring', 'amount' => '40.00']);
        $this->occurrence($user, ['kind' => 'recurring', 'direction' => 'credit', 'amount' => '60.00', 'due_date' => '2026-01-20']);
        $this->occurrence($user, ['amount' => '500.00', 'paid_at' => '2026-01-05', 'paid_amount' => '450.00']);
        $this->occurrence($user, ['amount' => '70.00']);
        $this->occurrence($user, ['category' => 'Lazer', 'amount' => '20.00']);
        $this->occurrence($user, ['kind' => 'recurring', 'amount' => '999.00', 'is_deleted' => true]);
        $this->occurrence(User::factory()->create(), ['kind' => 'recurring', 'amount' => '999.00']);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/dashboard?month=1&year=2026');

        $response->assertOk()->assertJsonPaths([
            'data.settlement.realized' => '740.00', 'data.settlement.pending' => '190.00',
            'data.settlement.recurring_realized' => '290.00', 'data.settlement.recurring_pending' => '100.00',
        ]);
        $categories = collect($response->json('data.categories'))->keyBy('category');
        $this->assertSame('260.00', $categories['Moradia']['recurring_credit']);
        $this->assertSame('140.00', $categories['Moradia']['recurring_debit']);
        $this->assertSame('970.00', $categories['Moradia']['total']);
        $this->assertSame('0.00', $categories['Lazer']['recurring_credit']);
        $this->assertSame('0.00', $categories['Lazer']['recurring_debit']);
        $this->getJson('/api/v1/dashboard?category=Lazer')->assertOk()
            ->assertJsonPath('data.settlement.recurring_realized', '0.00')->assertJsonPath('data.settlement.recurring_pending', '0.00');
        $this->getJson('/api/v1/dashboard?kind=recurring&status=pending')->assertOk()
            ->assertJsonPath('data.settlement.pending', '100.00')->assertJsonPath('data.settlement.recurring_pending', '100.00')
            ->assertJsonPath('data.settlement.recurring_realized', '0.00');
    }

    public function test_dashboard_groups_separate_due_months_instead_of_competence_or_payment_months(): void
    {
        $user = User::factory()->create();
        $this->occurrence($user, ['amount' => '10.00', 'due_date' => '2026-01-05', 'competence_date' => '2025-12-01', 'paid_at' => '2026-01-06', 'paid_amount' => '12.00']);
        $this->occurrence($user, ['amount' => '20.00', 'due_date' => '2026-02-05', 'competence_date' => '2026-01-01']);
        $this->actingAs($user);

        $response = $this->getJson('/api/v1/dashboard?from=2026-01-01&to=2026-02-28');

        $response->assertOk()->assertJsonPath('data.totals.debit', '30.00');
        $months = collect($response->json('data.months'))->keyBy('month');
        $this->assertSame('10.00', $months['2026-01']['debit']);
        $this->assertSame('12.00', $months['2026-01']['realized']);
        $this->assertSame('20.00', $months['2026-02']['debit']);
        $this->assertSame('20.00', $months['2026-02']['pending']);
    }

    public function test_dashboard_applies_the_same_combined_filters_to_every_aggregation_and_upcoming(): void
    {
        $user = User::factory()->create();
        $attributes = ['description' => 'Target rent', 'category' => 'Moradia', 'kind' => 'recurring', 'due_date' => '2026-01-20', 'amount' => '25.00'];
        $match = $this->occurrence($user, $attributes);
        foreach ([['category' => 'Lazer'], ['description' => 'Other'], ['direction' => 'credit'], ['kind' => 'single'], ['due_date' => '2026-01-21'], ['due_date' => '2026-01-14'], ['paid_at' => '2026-01-10', 'paid_amount' => '25.00']] as $difference) {
            $this->occurrence($user, array_replace($attributes, $difference));
        }
        $this->occurrence(User::factory()->create(), $attributes);
        $this->actingAs($user);
        $query = http_build_query(['month' => 1, 'year' => 2026, 'from' => '2026-01-15', 'to' => '2026-01-20', 'text' => 'Target', 'category' => 'Moradia', 'direction' => 'debit', 'kind' => 'recurring', 'status' => 'open', 'per_page' => 1]);

        $this->getJson('/api/v1/dashboard?'.$query)->assertOk()
            ->assertJsonPath('data.totals.debit', '25.00')->assertJsonPath('data.totals.credit', '0.00')
            ->assertJsonPath('data.settlement.pending', '25.00')->assertJsonPath('data.settlement.realized', '0.00')
            ->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.total', '25.00')
            ->assertJsonCount(1, 'data.upcoming')->assertJsonPath('data.upcoming.0.id', $match->id);
    }

    public function test_empty_account_reads_do_not_include_another_users_financial_data(): void
    {
        $this->occurrence(User::factory()->create(), ['description' => 'Private foreign data', 'category' => 'Segredo']);
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/plans')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/occurrences')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)->assertJsonPath('totals.credit', '0.00')->assertJsonPath('totals.debit', '0.00');
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.totals.credit', '0.00')->assertJsonPath('data.totals.debit', '0.00')
            ->assertJsonCount(0, 'data.categories')->assertJsonCount(0, 'data.upcoming');
        $export = $this->get('/api/v1/occurrences/export')->assertOk();
        $this->assertStringNotContainsString('Private foreign data', $export->streamedContent());
        $this->assertStringNotContainsString('Segredo', $export->streamedContent());
    }

    /** @param array<string, mixed> $changes
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

    /** @return array<string, Occurrence> */
    private function settlementOccurrences(User $user): array
    {
        $rows = [
            'open' => $this->occurrence($user, ['description' => 'Chart open', 'amount' => '10.00', 'due_date' => '2026-01-15']),
            'overdue' => $this->occurrence($user, ['description' => 'Chart overdue', 'amount' => '20.00', 'due_date' => '2026-01-14']),
            'paid' => $this->occurrence($user, ['description' => 'Chart paid', 'amount' => '30.00', 'paid_at' => '2026-01-05', 'paid_amount' => '25.00']),
            'paid_late' => $this->occurrence($user, ['description' => 'Chart paid_late', 'amount' => '40.00', 'paid_at' => '2026-01-10', 'paid_amount' => '50.00']),
        ];
        $this->occurrence($user, ['description' => 'Deleted chart', 'is_deleted' => true]);
        $this->occurrence(User::factory()->create(), ['description' => 'Foreign chart pending']);
        $this->occurrence(User::factory()->create(), ['description' => 'Foreign chart realized', 'paid_at' => '2026-01-10', 'paid_amount' => '999.00']);

        return $rows;
    }

    /** @param array<string, mixed> $changes */
    private function occurrence(User $user, array $changes = []): Occurrence
    {
        $attributes = array_replace([
            'description' => 'Aluguel', 'category' => 'Moradia', 'direction' => 'debit', 'kind' => 'single',
            'amount' => '100.00', 'competence_date' => '2026-01-01', 'due_date' => '2026-01-05',
        ], $changes);
        $plan = FinancialPlan::factory()->for($user)->create(array_intersect_key($attributes, array_flip([
            'description', 'category', 'direction', 'kind', 'amount', 'competence_date',
        ])) + ['first_due_date' => $attributes['due_date']]);

        return Occurrence::factory()->for($plan, 'financialPlan')->for($user)->create($attributes);
    }
}
