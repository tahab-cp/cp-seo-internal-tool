<?php

namespace App\Actions\MonthlyCycles;

use App\Enums\MonthlyCycleStatus;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The controlled HISTORICAL cycle path used by legacy migration only.
 *
 * Unlike CreateMonthlyCycleAction it never resolves the project's CURRENT
 * package targets: a historical month gets exactly the target snapshot the
 * legacy source provides (possibly none). An existing cycle for the period
 * is returned untouched, so targets already snapshotted are never
 * rewritten. Product code keeps using CreateMonthlyCycleAction.
 */
class CreateHistoricalMonthlyCycleAction
{
    /**
     * @param  array<string, array{label?: string, target_value: int}>  $targets  historical targets by key
     */
    public function handle(Project $project, CyclePeriod $period, array $targets = []): MonthlyCycle
    {
        if (! $project->exists || $project->trashed()) {
            throw new InvalidArgumentException('Historical cycles can only be created for existing, non-archived projects.');
        }

        return DB::transaction(function () use ($project, $period, $targets): MonthlyCycle {
            $existing = $project->monthlyCycles()->forPeriod($period)->with('targets')->first();

            if ($existing !== null) {
                return $existing;
            }

            $cycle = $project->monthlyCycles()->create([
                'year' => $period->year,
                'month' => $period->month,
                'status' => MonthlyCycleStatus::Open,
                'started_at' => $period->startOfMonth(),
            ]);

            foreach ($targets as $key => $target) {
                $value = $target['target_value'] ?? null;

                if (! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
                    throw new InvalidArgumentException("Historical target [{$key}] must be a whole number of 0 or more.");
                }

                $cycle->targets()->create([
                    'target_key' => (string) $key,
                    'label' => (string) ($target['label'] ?? ucwords(str_replace('_', ' ', (string) $key))),
                    'target_value' => (int) $value,
                ]);
            }

            return $cycle->load('targets');
        });
    }
}
