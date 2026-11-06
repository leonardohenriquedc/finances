<?php

namespace App\Http\Requests;

use App\Models\FinancialPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinancialPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('plan') !== null) {
            FinancialPlan::query()->where('user_id', $this->user()->id)->findOrFail($this->route('plan'));
        }

        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $firstDueDate = $this->input('first_due_date');
        if ($firstDueDate === null && $this->route('plan') !== null) {
            $firstDueDate = FinancialPlan::query()->where('user_id', $this->user()->id)->findOrFail($this->route('plan'))->first_due_date->toDateString();
        }

        return [
            'description' => [$presence, 'string', 'max:255'],
            'category' => [$presence, 'string', 'max:255'],
            'direction' => [$presence, 'in:debit,credit'],
            'priority' => ['nullable', Rule::in(array_keys(FinancialPlan::PRIORITIES))],
            'kind' => [$presence, 'in:single,installment,recurring'],
            'amount' => [$presence, 'numeric', 'min:0.01', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'amount_mode' => ['sometimes', 'in:total,per_installment'],
            'installment_count' => ['sometimes', 'integer', 'between:1,360'],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly,yearly'],
            'interest_type' => ['sometimes', 'in:none,simple,compound'],
            'interest_rate' => ['sometimes', 'numeric', 'min:0', 'regex:/^\d{1,4}(\.\d{1,4})?$/'],
            'interest_period' => ['sometimes', 'in:daily,monthly,yearly'],
            'competence_date' => [$presence, 'date_format:Y-m-d'],
            'first_due_date' => [$presence, 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', ...(is_string($firstDueDate) ? ['after_or_equal:'.$firstDueDate] : [])],
            'occurrence_limit' => ['nullable', 'integer', 'between:1,10000'],
            'scope' => ['sometimes', 'in:all,future'],
            'occurrence_id' => ['required_if:scope,future', 'integer'],
        ];
    }
}
