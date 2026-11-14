<?php

namespace App\Http\Requests;

use App\Models\FinancialPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OccurrenceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:1900,9999', 'prohibits:year_from,year_to'],
            'year_from' => ['nullable', 'required_with:year_to', 'integer', 'between:1900,9999'],
            'year_to' => ['nullable', 'required_with:year_from', 'integer', 'between:1900,9999', 'gte:year_from'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'category' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', Rule::in(array_keys(FinancialPlan::PRIORITIES))],
            'status' => ['nullable', 'in:open,overdue,paid,paid_late,pending,realized'],
            'text' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', 'in:debit,credit'],
            'kind' => ['nullable', 'in:single,installment,recurring'],
            'sort' => ['nullable', 'in:due_date,amount,description,category,status,priority'],
            'order' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
