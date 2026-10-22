<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'category' => $this->category,
            'direction' => $this->direction,
            'kind' => $this->kind,
            'amount' => $this->amount,
            'amount_mode' => $this->amount_mode,
            'installment_count' => $this->installment_count,
            'frequency' => $this->frequency,
            'interest_type' => $this->interest_type,
            'interest_rate' => $this->interest_rate,
            'interest_period' => $this->interest_period,
            'competence_date' => $this->competence_date->toDateString(),
            'first_due_date' => $this->first_due_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'occurrence_limit' => $this->occurrence_limit,
            'state' => $this->state,
        ];
    }
}
