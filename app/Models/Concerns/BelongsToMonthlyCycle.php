<?php

namespace App\Models\Concerns;

use App\Models\MonthlyCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared shape of monthly analytics rows: they belong to exactly one
 * MonthlyCycle (which determines the Project), inherit its lock, and are
 * visible exactly when the project is visible.
 */
trait BelongsToMonthlyCycle
{
    /**
     * @return BelongsTo<MonthlyCycle, $this>
     */
    public function monthlyCycle(): BelongsTo
    {
        return $this->belongsTo(MonthlyCycle::class);
    }

    public function isLocked(): bool
    {
        return $this->monthlyCycle?->isLocked() ?? false;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAccessibleBy(Builder $query, ?User $user): Builder
    {
        return $query->whereHas(
            'monthlyCycle',
            fn (Builder $cycle) => $cycle->whereHas('project', fn (Builder $project) => $project->accessibleBy($user)),
        );
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCycle(Builder $query, MonthlyCycle|int $cycle): Builder
    {
        return $query->where('monthly_cycle_id', $cycle instanceof MonthlyCycle ? $cycle->getKey() : $cycle);
    }
}
