<?php

namespace App\Services\MonthlyCycles;

use App\Models\MonthlyCycle;
use App\Models\MonthlyCycleTarget;
use App\Support\Targets\MonthlyTargetCompletion;
use App\Support\Targets\TargetCompletionRow;
use Illuminate\Support\Collection;

/**
 * Monthly Target Completion, always from the cycle's own target snapshot
 * (never today's package or overrides) and the live operational actuals
 * derived by TargetProgressService.
 *
 * Rules:
 * - every MonthlyCycleTarget row becomes a row; only supported keys with a
 *   positive target participate in the aggregate;
 * - individual percentages are uncapped for display; contributions are
 *   capped at 100 so over-delivery never hides a missed target;
 * - overall = average of participating contributions, equal weights;
 * - no snapshot rows, or none participating → no figure (null), never 0%.
 */
class MonthlyTargetCompletionService
{
    public function __construct(
        protected TargetProgressService $progress,
    ) {}

    public function for(MonthlyCycle $cycle): MonthlyTargetCompletion
    {
        return $this->forCycles(new Collection([$cycle]))->get($cycle->getKey());
    }

    /**
     * Batch variant for dashboards: one grouped query per target key
     * instead of four per cycle.
     *
     * @param  Collection<int, MonthlyCycle>  $cycles
     * @return Collection<int, MonthlyTargetCompletion> keyed by cycle id
     */
    public function forCycles(Collection $cycles): Collection
    {
        $ids = $cycles->map(fn (MonthlyCycle $cycle): int => (int) $cycle->getKey())->all();
        $actuals = $this->progress->actualsForCycles($ids);

        $targets = MonthlyCycleTarget::query()
            ->whereIn('monthly_cycle_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('monthly_cycle_id');

        return $cycles->mapWithKeys(function (MonthlyCycle $cycle) use ($targets, $actuals): array {
            $id = (int) $cycle->getKey();

            $rows = ($targets->get($id) ?? new Collection)->map(function (MonthlyCycleTarget $target) use ($actuals, $id): TargetCompletionRow {
                $supported = TargetProgressService::supports($target->target_key);

                return new TargetCompletionRow(
                    targetKey: $target->target_key,
                    label: $target->label,
                    target: (int) $target->target_value,
                    actual: $supported ? ($actuals[$id][$target->target_key] ?? 0) : null,
                    supported: $supported,
                );
            })->values();

            return [$id => new MonthlyTargetCompletion($id, $rows)];
        });
    }
}
