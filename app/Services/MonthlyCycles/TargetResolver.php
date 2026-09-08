<?php

namespace App\Services\MonthlyCycles;

use App\Models\PackageTarget;
use App\Models\Project;
use App\Support\Targets\ResolvedTarget;
use Illuminate\Support\Collection;

/**
 * Resolves a project's *current* monthly targets:
 *
 *   project override → otherwise package target
 *
 * Reads live configuration only. Milestone 5 snapshots the result into
 * monthly_cycle_targets when a cycle is created; historical cycles are
 * never re-resolved from here.
 */
class TargetResolver
{
    /**
     * Targets in the package's configured order. A project without a
     * package resolves to an empty collection. An inactive package that is
     * still assigned resolves normally.
     *
     * @return Collection<int, ResolvedTarget>
     */
    public function resolve(Project $project): Collection
    {
        $package = $project->package()->with('targets')->first();

        if ($package === null) {
            return collect();
        }

        $overrides = $project->targetOverrides()->pluck('target_value', 'target_key');

        return $package->targets
            ->map(fn (PackageTarget $target): ResolvedTarget => new ResolvedTarget(
                targetKey: $target->target_key,
                label: $target->label,
                packageValue: (int) $target->target_value,
                // Overrides for keys the package no longer defines are ignored.
                overrideValue: $overrides->has($target->target_key) ? (int) $overrides[$target->target_key] : null,
                sortOrder: (int) $target->sort_order,
            ))
            ->values();
    }

    /**
     * Convenience map of target_key => resolved value.
     *
     * @return array<string, int>
     */
    public function resolveValues(Project $project): array
    {
        return $this->resolve($project)
            ->mapWithKeys(fn (ResolvedTarget $target): array => [$target->targetKey => $target->resolvedValue()])
            ->all();
    }
}
