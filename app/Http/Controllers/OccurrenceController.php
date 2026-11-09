<?php

namespace App\Http\Controllers;

use App\Http\Requests\OccurrenceFilterRequest;
use App\Http\Resources\OccurrenceResource;
use App\Models\FinancialPlan;
use App\Models\Occurrence;
use App\Services\FinancialQuery;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OccurrenceController extends Controller
{
    public function __construct(private FinancialQuery $financialQuery) {}

    public function index(OccurrenceFilterRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = $this->financialQuery->filtered($request->user(), $filters);
        $totals = $this->financialQuery->aggregate($query)['totals'];
        $page = $this->financialQuery->sorted($query, $filters)->with('financialPlan')->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => OccurrenceResource::collection($page->getCollection()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'totals' => $totals,
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $categories = Occurrence::query()->where('user_id', $request->user()->id)->where('is_deleted', false)->distinct()->pluck('category')
            ->merge(FinancialPlan::query()->where('user_id', $request->user()->id)->distinct()->pluck('category'));

        return response()->json(['data' => $categories->unique()->sort()->values()]);
    }

    public function update(Request $request, int $occurrence): OccurrenceResource
    {
        $row = $this->owned($request, $occurrence);
        $data = $request->validate([
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'priority' => ['nullable', Rule::in(array_keys(FinancialPlan::PRIORITIES))],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'competence_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);
        $updated = $this->mutate($row, function (Occurrence $locked) use ($data): void {
            $this->requireUnpaid($locked);
            $this->requireFutureFinancialEdit($locked, $data);
            $attributes = $data;
            if (array_key_exists('priority', $attributes)) {
                $attributes['priority'] = $locked->direction === 'debit' ? ($attributes['priority'] ?? 'when_possible') : null;
            }
            $locked->fill([...$attributes, 'is_override' => true])->save();
        });

        return new OccurrenceResource($updated);
    }

    public function destroy(Request $request, int $occurrence): Response
    {
        $this->mutate($this->owned($request, $occurrence), function (Occurrence $locked): void {
            $this->requireUnpaid($locked);
            $locked->is_deleted = true;
            $locked->save();
        });

        return response()->noContent();
    }

    public function payment(Request $request, int $occurrence): OccurrenceResource
    {
        $row = $this->owned($request, $occurrence);
        $data = $request->validate([
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'paid_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ]);
        $updated = $this->mutate($row, function (Occurrence $locked) use ($data): void {
            $locked->fill($data)->save();
        });

        return new OccurrenceResource($updated);
    }

    public function reopen(Request $request, int $occurrence): OccurrenceResource
    {
        $updated = $this->mutate($this->owned($request, $occurrence), function (Occurrence $locked): void {
            $locked->paid_at = null;
            $locked->paid_amount = null;
            $locked->save();
        });

        return new OccurrenceResource($updated);
    }

    public function export(OccurrenceFilterRequest $request): StreamedResponse
    {
        $query = $this->financialQuery->sorted($this->financialQuery->filtered($request->user(), $request->validated()), $request->validated());

        return response()->streamDownload(function () use ($query): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Descrição', 'Categoria', 'Prioridade', 'Direção', 'Tipo', 'Competência', 'Vencimento', 'Valor previsto (BRL)', 'Situação', 'Data da baixa', 'Valor realizado (BRL)'], ',', '"', '');
            $statuses = ['open' => 'Em aberto', 'overdue' => 'Vencida', 'paid' => 'Paga', 'paid_late' => 'Paga em atraso'];
            foreach ($query->cursor() as $row) {
                fputcsv($file, [
                    $this->csvText($row->description), $this->csvText($row->category),
                    FinancialPlan::PRIORITIES[$row->priority] ?? '',
                    $row->direction === 'credit' ? 'Crédito' : 'Débito',
                    ['single' => 'Único', 'installment' => 'Parcelado', 'recurring' => 'Recorrente'][$row->kind],
                    $row->competence_date->toDateString(), $row->due_date->toDateString(), $row->amount,
                    $statuses[$row->status], $row->paid_at?->toDateString(), $row->paid_amount,
                ], ',', '"', '');
            }
            fclose($file);
        }, 'lancamentos-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function csvText(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@\t\r\n]/u', $value) ? "'".$value : $value;
    }

    private function owned(Request $request, int $id): Occurrence
    {
        return Occurrence::query()->where('user_id', $request->user()->id)->where('is_deleted', false)->findOrFail($id);
    }

    private function requireUnpaid(Occurrence $row): void
    {
        if ($row->paid_at !== null) {
            throw ValidationException::withMessages(['status' => 'Reabra explicitamente a baixa antes de alterar ou excluir uma ocorrência realizada.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function requireFutureFinancialEdit(Occurrence $row, array $data): void
    {
        if (! in_array($row->kind, ['installment', 'recurring'], true)) {
            return;
        }
        $historical = $row->due_date->lessThanOrEqualTo(today());
        $errors = [];
        if ($historical && isset($data['amount']) && ! BigDecimal::of($row->amount)->isEqualTo($data['amount'])) {
            $errors['amount'] = 'O valor de uma parcela ou recorrência com vencimento hoje ou anterior é preservado. Altere somente ocorrências futuras.';
        }
        if (isset($data['due_date']) && $data['due_date'] !== $row->due_date->toDateString()
            && ($historical || $data['due_date'] <= today()->toDateString())) {
            $errors['due_date'] = 'O vencimento histórico é preservado. Uma ocorrência futura só pode ser remarcada para outra data futura.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param Closure(Occurrence): void $operation */
    private function mutate(Occurrence $row, Closure $operation): Occurrence
    {
        return DB::transaction(function () use ($row, $operation): Occurrence {
            FinancialPlan::query()->whereKey($row->financial_plan_id)->lockForUpdate()->firstOrFail();
            $locked = Occurrence::query()->where('user_id', $row->user_id)->where('is_deleted', false)->lockForUpdate()->findOrFail($row->id);
            $operation($locked);

            return $locked->load('financialPlan');
        }, 3);
    }
}
