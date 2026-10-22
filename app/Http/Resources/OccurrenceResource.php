<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OccurrenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'financial_plan_id' => $this->financial_plan_id,
            'sequence' => $this->sequence,
            'description' => $this->description,
            'category' => $this->category,
            'direction' => $this->direction,
            'kind' => $this->kind,
            'amount' => $this->amount,
            'competence_date' => $this->competence_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'paid_at' => $this->paid_at?->toDateString(),
            'paid_amount' => $this->paid_amount,
            'status' => $this->status,
            'is_override' => $this->is_override,
            'plan' => new FinancialPlanResource($this->whenLoaded('financialPlan')),
        ];
    }
}
