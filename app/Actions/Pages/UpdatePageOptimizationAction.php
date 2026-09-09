<?php

namespace App\Actions\Pages;

use App\Models\PageOptimization;
use App\Services\Pages\PageOptimizationGuard;
use Illuminate\Support\Facades\DB;

class UpdatePageOptimizationAction
{
    public function __construct(
        protected PageOptimizationGuard $guard,
    ) {}

    /**
     * Edit an optimisation event. Refused entirely while its cycle is
     * locked; the project itself never changes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(PageOptimization $optimization, array $attributes): PageOptimization
    {
        return DB::transaction(function () use ($optimization, $attributes): PageOptimization {
            $project = $optimization->project;

            // Source and (possible) destination cycles are row-locked together,
            // in deterministic order, and re-checked fresh.
            $moving = array_key_exists('monthly_cycle_id', $attributes);
            $destination = $moving ? $this->guard->resolveCycle($project, $attributes['monthly_cycle_id']) : null;

            $this->guard->lockCyclesForMove(
                $optimization->monthly_cycle_id,
                $moving ? $destination->getKey() : $optimization->monthly_cycle_id,
                'edit page optimisations in it',
                'move page optimisations into it',
            );

            if (array_key_exists('page_id', $attributes)) {
                $page = $this->guard->resolvePage($project, $attributes['page_id']);
                $optimization->page_id = $page->getKey();
            }

            if ($moving) {
                $optimization->monthly_cycle_id = $destination->getKey();
            }

            if (array_key_exists('user_id', $attributes)) {
                $this->guard->ensureRecordedBy($project, $attributes['user_id']);
                $optimization->user_id = filled($attributes['user_id']) ? (int) $attributes['user_id'] : null;
            }

            $optimization->fill($this->guard->normaliseChanges($attributes, $optimization));

            if (array_key_exists('optimized_at', $attributes)) {
                $optimization->optimized_at = $attributes['optimized_at'];
            }

            if (array_key_exists('notes', $attributes)) {
                $optimization->notes = filled($attributes['notes']) ? (string) $attributes['notes'] : null;
            }

            $optimization->save();

            return $optimization;
        });
    }
}
