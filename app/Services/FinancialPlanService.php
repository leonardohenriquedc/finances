<?php

namespace App\Services;

use App\Models\FinancialPlan;
use App\Models\Occurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FinancialPlanService
{
    /** Lifetime safety ceiling, including plans without an explicit occurrence_limit. */
    private const MAX_RECURRING_OCCURRENCES = 10000;

    private const EDITABLE = [
        'description', 'category', 'direction', 'kind', 'amount', 'amount_mode',
        'installment_count', 'frequency', 'interest_type', 'interest_rate', 'interest_period',
        'competence_date', 'first_due_date', 'end_date', 'occurrence_limit',
    ];

    public function __construct(private FinancialAmountCalculator $calculator) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): FinancialPlan
    {
        return DB::transaction(function () use ($user, $data): FinancialPlan {
            $attributes = $this->validated(array_replace([
                'amount_mode' => 'total', 'installment_count' => 1, 'frequency' => 'monthly',
                'interest_type' => 'none', 'interest_rate' => '0.0000', 'interest_period' => 'monthly',
                'end_date' => null, 'occurrence_limit' => null,
            ], Arr::only($data, self::EDITABLE)));
            $plan = FinancialPlan::create([...$attributes, 'user_id' => $user->getKey(), 'state' => 'active']);
            $this->project($plan);

            return $plan->refresh();
        }, 3);
    }

    /**
     * Future scope uses the anchor sequence inclusively; dates remain relative to the plan's
     * original first_due_date, not the anchor. Earlier rows, paid rows, overrides and tombstones
     * remain unchanged. Ordinary generation only inserts missing rows; active updates rebuild pending
     * rows. Before future edits, earlier gaps are frozen with old terms (or tombstones for paused
     * dates). Paused edits retain existing overdue rows and apply new terms when resumed. Ended
     * plans reject edits. Preserving paid amounts can make revised totals differ from history.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FinancialPlan $plan, array $data, string $scope = 'all', ?Occurrence $from = null): FinancialPlan
    {
        return DB::transaction(function () use ($plan, $data, $scope, $from): FinancialPlan {
            $locked = $this->lock($plan);
            if (! in_array($scope, ['all', 'future'], true)) {
                throw ValidationException::withMessages(['scope' => 'Use all ou future; edite ocorrências individuais pelo endpoint de ocorrências.']);
            }
            $sequence = 1;
            if ($scope === 'future') {
                $anchor = $from === null ? null : $locked->occurrences()->whereKey($from->getKey())->lockForUpdate()->first();
                if ($anchor === null || $anchor->is_deleted) {
                    throw ValidationException::withMessages(['occurrence_id' => 'Informe uma ocorrência visível pertencente a este planejamento.']);
                }
                $sequence = $anchor->sequence;
            }
            if ($locked->state === 'ended') {
                throw ValidationException::withMessages(['state' => 'Não é possível editar um planejamento encerrado.']);
            }
            $current = Arr::only($locked->getAttributes(), self::EDITABLE);
            foreach (['competence_date', 'first_due_date', 'end_date'] as $dateField) {
                $current[$dateField] = $locked->{$dateField}?->toDateString();
            }
            $attributes = $this->validated(array_replace($current, Arr::only($data, self::EDITABLE)));
            if ($attributes['kind'] !== $locked->kind && $locked->occurrences()->whereNotNull('paid_at')->exists()) {
                throw ValidationException::withMessages(['kind' => 'Não é possível alterar o tipo de um planejamento com pagamentos registrados.']);
            }
            if ($scope === 'future') {
                $this->preserveBeforeAnchor($locked, $sequence);
            }
            $locked->fill($attributes)->save();
            if ($locked->state === 'active') {
                $locked->occurrences()->where('sequence', '>=', $sequence)->whereNull('paid_at')
                    ->where('is_override', false)->where('is_deleted', false)->delete();
            }
            $this->project($locked, $sequence);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Plans without payments are removed. Otherwise delete means archive: keep every paid row,
     * remove every unpaid row (including overrides/tombstones), and end the plan permanently.
     */
    public function delete(FinancialPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $locked = $this->lock($plan);
            if (! $locked->occurrences()->whereNotNull('paid_at')->exists()) {
                $locked->delete();

                return;
            }
            $locked->occurrences()->whereNull('paid_at')->delete();
            $locked->state = 'ended';
            $locked->save();
        }, 3);
    }

    /**
     * Pause/end discard unpaid rows due today or later, including future overrides, but retain
     * tombstones and all paid/overdue history. Resume never backfills missing dates before today.
     * An ended plan cannot resume. Repeating the same transition is idempotent.
     */
    public function transition(FinancialPlan $plan, string $action): FinancialPlan
    {
        return DB::transaction(function () use ($plan, $action): FinancialPlan {
            $locked = $this->lock($plan);
            $state = match ($action) {
                'pause' => 'paused',
                'resume' => 'active',
                'end' => 'ended',
                default => throw ValidationException::withMessages(['action' => 'Use pause, resume ou end para pausar, retomar ou encerrar o planejamento.']),
            };
            if ($locked->state === $state) {
                return $locked;
            }
            if ($locked->state === 'ended') {
                throw ValidationException::withMessages(['state' => 'Não é possível reativar um planejamento encerrado.']);
            }
            if ($state === 'active') {
                $locked->projection_resume_date = CarbonImmutable::today();
            } else {
                $locked->occurrences()->whereNull('paid_at')->where('is_deleted', false)
                    ->whereDate('due_date', '>=', today())->delete();
            }
            $locked->state = $state;
            $locked->save();
            $this->project($locked);

            return $locked->refresh();
        }, 3);
    }

    public function generate(FinancialPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $this->project($this->lock($plan));
        }, 3);
    }

    private function lock(FinancialPlan $plan): FinancialPlan
    {
        return FinancialPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
    }

    private function project(FinancialPlan $plan, int $fromSequence = 1): void
    {
        if ($plan->state !== 'active') {
            return;
        }
        $count = match ($plan->kind) {
            'single' => 1,
            'installment' => $plan->installment_count,
            'recurring' => $plan->occurrence_limit ?? self::MAX_RECURRING_OCCURRENCES,
        };
        $horizon = CarbonImmutable::today()->addMonthsNoOverflow(max(1, min(60, (int) config('finance.projection_months', 12))));
        $existing = $plan->occurrences()->pluck('sequence')->flip();

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $dueDate = $this->scheduleDate($plan->first_due_date, $plan->frequency, $sequence - 1);
            if ($plan->kind === 'recurring' && ($dueDate->greaterThan($horizon) || ($plan->end_date !== null && $dueDate->greaterThan($plan->end_date)))) {
                break;
            }
            if ($sequence < $fromSequence || $existing->has($sequence) || ($plan->projection_resume_date !== null && $dueDate->lessThan($plan->projection_resume_date))) {
                continue;
            }
            $this->createOccurrence($plan, $sequence, $dueDate);
        }
    }

    /**
     * Freeze the entire prefix, not just rows present at edit time. A later generate() must never
     * apply new terms to earlier gaps, including dates skipped while the plan was paused.
     */
    private function preserveBeforeAnchor(FinancialPlan $plan, int $anchor): void
    {
        $existing = $plan->occurrences()->where('sequence', '<', $anchor)->pluck('sequence')->flip();
        for ($sequence = 1; $sequence < $anchor; $sequence++) {
            if ($existing->has($sequence)) {
                continue;
            }
            $dueDate = $this->scheduleDate($plan->first_due_date, $plan->frequency, $sequence - 1);
            $suppressed = ($plan->state === 'paused' && $dueDate->greaterThanOrEqualTo(CarbonImmutable::today()))
                || ($plan->projection_resume_date !== null && $dueDate->lessThan($plan->projection_resume_date))
                || ($plan->kind === 'single' && $sequence > 1)
                || ($plan->kind === 'installment' && $sequence > $plan->installment_count)
                || ($plan->kind === 'recurring' && (($plan->end_date !== null && $dueDate->greaterThan($plan->end_date))
                    || $sequence > ($plan->occurrence_limit ?? self::MAX_RECURRING_OCCURRENCES)));
            $this->createOccurrence($plan, $sequence, $dueDate, $suppressed);
        }
    }

    private function createOccurrence(FinancialPlan $plan, int $sequence, CarbonImmutable $dueDate, bool $deleted = false): void
    {
        $plan->occurrences()->create([
            'user_id' => $plan->user_id,
            'sequence' => $sequence,
            'description' => $plan->description,
            'category' => $plan->category,
            'direction' => $plan->direction,
            'kind' => $plan->kind,
            'amount' => $this->calculator->amount($plan, $sequence, $dueDate),
            'competence_date' => $plan->kind === 'recurring'
                ? $this->scheduleDate($plan->competence_date, $plan->frequency, $sequence - 1)->toDateString()
                : $plan->competence_date->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'is_override' => false,
            'is_deleted' => $deleted,
        ]);
    }

    private function scheduleDate(CarbonImmutable $base, string $frequency, int $offset): CarbonImmutable
    {
        return match ($frequency) {
            'daily' => $base->addDays($offset),
            'weekly' => $base->addWeeks($offset),
            'monthly' => $base->addMonthsNoOverflow($offset),
            'yearly' => $base->addYearsNoOverflow($offset),
        };
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        if (in_array($data['kind'] ?? null, ['single', 'recurring'], true)) {
            $data['installment_count'] = 1;
            $data['amount_mode'] = $data['kind'] === 'recurring' ? 'per_installment' : 'total';
            $data['interest_type'] = 'none';
            $data['interest_rate'] = '0.0000';
            $data['interest_period'] = 'monthly';
        }
        if (in_array($data['kind'] ?? null, ['single', 'installment'], true)) {
            $data['end_date'] = null;
            $data['occurrence_limit'] = null;
        }

        return Validator::make($data, [
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'direction' => ['required', 'in:debit,credit'],
            'kind' => ['required', 'in:single,installment,recurring'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'amount_mode' => ['required', 'in:total,per_installment'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:360'],
            'frequency' => ['required', 'in:daily,weekly,monthly,yearly'],
            'interest_type' => ['required', 'in:none,simple,compound'],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:9999.9999', 'regex:/^\d{1,4}(\.\d{1,4})?$/'],
            'interest_period' => ['required', 'in:daily,monthly,yearly'],
            'competence_date' => ['required', 'date_format:Y-m-d'],
            'first_due_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:first_due_date'],
            'occurrence_limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_RECURRING_OCCURRENCES],
        ], [
            'required' => 'O campo :attribute é obrigatório.',
            'string' => 'O campo :attribute deve ser um texto.',
            'numeric' => 'O campo :attribute deve ser um número.',
            'integer' => 'O campo :attribute deve ser um número inteiro.',
            'in' => 'O valor selecionado para :attribute é inválido.',
            'min' => 'O campo :attribute deve ser no mínimo :min.',
            'max' => 'O campo :attribute deve ser no máximo :max.',
            'description.max' => 'O campo :attribute deve ter no máximo :max caracteres.',
            'category.max' => 'O campo :attribute deve ter no máximo :max caracteres.',
            'regex' => 'O formato do campo :attribute é inválido.',
            'date_format' => 'O campo :attribute deve estar no formato AAAA-MM-DD.',
            'after_or_equal' => 'O campo :attribute deve ser uma data igual ou posterior a :date.',
        ], [
            'description' => 'descrição', 'category' => 'categoria', 'direction' => 'direção',
            'kind' => 'tipo', 'amount' => 'valor', 'amount_mode' => 'modo do valor',
            'installment_count' => 'quantidade de parcelas', 'frequency' => 'frequência',
            'interest_type' => 'tipo de juros', 'interest_rate' => 'taxa de juros',
            'interest_period' => 'período dos juros', 'competence_date' => 'data de competência',
            'first_due_date' => 'primeiro vencimento', 'end_date' => 'data final',
            'occurrence_limit' => 'limite de ocorrências',
        ])->validate();

    }
}
