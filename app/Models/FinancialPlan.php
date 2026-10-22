<?php

namespace App\Models;

use Database\Factories\FinancialPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'description', 'category', 'direction', 'kind', 'amount', 'amount_mode', 'installment_count', 'frequency', 'interest_type', 'interest_rate', 'interest_period', 'competence_date', 'first_due_date', 'end_date', 'occurrence_limit', 'state'])]
class FinancialPlan extends Model
{
    /** @use HasFactory<FinancialPlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interest_rate' => 'decimal:4',
            'installment_count' => 'integer',
            'occurrence_limit' => 'integer',
            'competence_date' => 'immutable_date',
            'first_due_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'projection_resume_date' => 'immutable_date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class);
    }
}
