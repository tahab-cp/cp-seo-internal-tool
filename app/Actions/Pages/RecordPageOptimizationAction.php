<?php

namespace App\Actions\Pages;

use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;
use App\Services\Pages\PageOptimizationGuard;
use Illuminate\Support\Facades\DB;

class RecordPageOptimizationAction
{
    public function __construct(
        protected PageOptimizationGuard $guard,
    ) {}

    /**
     * Record one optimisation event on a page for a reporting month.
     *
     * @param  array<string, mixed>  $attributes  page_id, monthly_cycle_id, optimized_at, user_id, the five flags, notes
     * @param  User|null  $recordedBy  default user when $attributes has no user_id
     */
    public function handle(Project $project, array $attributes, ?User $recordedBy = null): PageOptimization
    {
        return DB::transaction(function () use ($project, $attributes, $recordedBy): PageOptimization {
            $page = $this->guard->resolvePage($project, $attributes['page_id'] ?? null);
            $this->guard->ensurePageOptimisable($page);

            $cycle = $this->guard->resolveCycle($project, $attributes['monthly_cycle_id'] ?? null);
            $this->guard->ensureCycleNotLocked($cycle, 'record page optimisations in it');

            $userId = array_key_exists('user_id', $attributes) ? $attributes['user_id'] : $recordedBy?->getKey();
            $this->guard->ensureRecordedBy($project, $userId);

            $flags = $this->guard->normaliseChanges($attributes);

            $optimization = new PageOptimization($flags + [
                'optimized_at' => $attributes['optimized_at'] ?? now(),
                'notes' => filled($attributes['notes'] ?? null) ? (string) $attributes['notes'] : null,
            ]);

            $optimization->project_id = $project->getKey();
            $optimization->page_id = $page->getKey();
            $optimization->monthly_cycle_id = $cycle->getKey();
            $optimization->user_id = filled($userId) ? (int) $userId : null;

            $optimization->save();

            return $optimization;
        });
    }
}
