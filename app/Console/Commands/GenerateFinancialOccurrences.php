<?php

namespace App\Console\Commands;

use App\Models\FinancialPlan;
use App\Services\FinancialPlanService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class GenerateFinancialOccurrences extends Command
{
    protected $signature = 'finance:generate-occurrences';

    protected $description = 'Extend active recurring plans through the configured finite projection horizon';

    public function handle(FinancialPlanService $service): int
    {
        FinancialPlan::query()->where('state', 'active')->where('kind', 'recurring')
            ->chunkById(100, function (Collection $plans) use ($service): void {
                foreach ($plans as $plan) {
                    $service->generate($plan);
                }
            });

        $this->info('Financial occurrences generated.');

        return self::SUCCESS;
    }
}
