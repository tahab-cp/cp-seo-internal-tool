<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Services\ActiveUserGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateProjectAction
{
    public function __construct(
        protected SyncProjectTeamAction $syncProjectTeam,
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * Update project attributes. When the primary SEO owner changes, the
     * team list is re-synced so the new owner is never also an additional
     * member.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Project $project, array $attributes): Project
    {
        return DB::transaction(function () use ($project, $attributes): Project {
            $project->fill(Arr::only($attributes, [
                'client_id',
                'name',
                'website_url',
                'target_location',
                'status',
                'start_date',
                'end_date',
                'primary_seo_user_id',
                'notes',
            ]));

            $ownerChanged = $project->isDirty('primary_seo_user_id');

            if ($ownerChanged) {
                $this->activeUsers->ensureActive([$project->primary_seo_user_id], 'the primary SEO owner');
            }

            $project->save();

            if ($ownerChanged) {
                $this->syncProjectTeam->handle(
                    $project,
                    $project->teamMembers()->pluck('users.id')->all(),
                );
            }

            return $project;
        });
    }
}
