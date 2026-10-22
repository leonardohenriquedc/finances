<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancialPlanRequest;
use App\Http\Resources\FinancialPlanResource;
use App\Models\FinancialPlan;
use App\Services\FinancialPlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FinancialPlanController extends Controller
{
    public function __construct(private FinancialPlanService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['kind' => ['nullable', 'in:single,installment,recurring']]);
        $query = FinancialPlan::query()->where('user_id', $request->user()->id);
        if (! empty($data['kind'])) {
            $query->where('kind', $data['kind']);
        }

        return FinancialPlanResource::collection($query->orderByDesc('id')->get());
    }

    public function show(Request $request, int $plan): FinancialPlanResource
    {
        return new FinancialPlanResource($this->owned($request, $plan));
    }

    public function store(FinancialPlanRequest $request): FinancialPlanResource
    {
        return new FinancialPlanResource($this->service->create($request->user(), $request->validated()));
    }

    public function update(FinancialPlanRequest $request, int $plan): FinancialPlanResource
    {
        $model = $this->owned($request, $plan);
        $data = $request->validated();
        $anchor = isset($data['occurrence_id']) ? $model->occurrences()->where('is_deleted', false)->find($data['occurrence_id']) : null;

        return new FinancialPlanResource($this->service->update($model, $data, $data['scope'] ?? 'all', $anchor));
    }

    public function destroy(Request $request, int $plan): Response
    {
        $this->service->delete($this->owned($request, $plan));

        return response()->noContent();
    }

    public function lifecycle(Request $request, int $plan): FinancialPlanResource
    {
        $model = $this->owned($request, $plan);
        $data = $request->validate(['action' => ['required', 'in:pause,resume,end']]);
        abort_unless($model->kind === 'recurring', 422, 'Somente recorrências podem ser pausadas, retomadas ou encerradas.');

        return new FinancialPlanResource($this->service->transition($model, $data['action']));
    }

    private function owned(Request $request, int $id): FinancialPlan
    {
        return FinancialPlan::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
