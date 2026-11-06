<?php

namespace App\Models;

use Database\Factories\OccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['financial_plan_id', 'user_id', 'sequence', 'description', 'category', 'direction', 'priority', 'kind', 'amount', 'competence_date', 'due_date', 'paid_at', 'paid_amount', 'is_override', 'is_deleted'])]
class Occurrence extends Model
{
    /** @use HasFactory<OccurrenceFactory> */
    use HasFactory;

    protected $appends = ['status'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'sequence' => 'integer',
            'competence_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'paid_at' => 'immutable_date',
            'is_override' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->paid_at !== null) {
                return $this->paid_at->greaterThan($this->due_date) ? 'paid_late' : 'paid';
            }

            return $this->due_date->lessThan(today()) ? 'overdue' : 'open';
        });
    }

    public function financialPlan(): BelongsTo
    {
        return $this->belongsTo(FinancialPlan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
