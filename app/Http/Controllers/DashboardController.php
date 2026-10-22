<?php

namespace App\Http\Controllers;

use App\Http\Requests\OccurrenceFilterRequest;
use App\Http\Resources\OccurrenceResource;
use App\Services\FinancialQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(OccurrenceFilterRequest $request, FinancialQuery $financialQuery): JsonResponse
    {
        $query = $financialQuery->filtered($request->user(), $request->validated());
        $data = $financialQuery->aggregate($query);
        $data['upcoming'] = OccurrenceResource::collection((clone $query)->whereNull('paid_at')
            ->whereDate('due_date', '>=', today())->whereDate('due_date', '<=', today()->addDays(30))
            ->with('financialPlan')->orderBy('due_date')->orderBy('id')->limit(10)->get());
        $data['projection_end'] = CarbonImmutable::today()->addMonthsNoOverflow((int) config('finance.projection_months', 12))->toDateString();

        return response()->json(['data' => $data]);
    }
}
