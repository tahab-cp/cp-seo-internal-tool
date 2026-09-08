<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Services\ActiveUserGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The transaction boundary for the complete project update workflow.
 *
 * Attributes, team, package change (with its stale-override clearing) and
 * new target overrides are applied inside one transaction: the whole save
 * succeeds or rolls back as a unit. Callers such as the Filament edit page
 * decide *which* parts the actor may change and pass null for the rest.
 */
class UpdateProjectAction
{
    public function __construct(
        protected SyncProjectTeamAction $syncProjectTeam,
        protected ChangeProjectPackageAction $changeProjectPackage,
        protected SyncProjectTargetOverridesAction $syncTargetOverrides,
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  May include package_id; a change clears stale overrides.
     * @param  list<int|string>|null  $teamMemberIds  null leaves the team unchanged.
     * @param  array<string, int|string>|null  $targetOverrides  target_key => value; null leaves overrides unchanged.
     */
    public function handle(
        Project $project,
        array $attributes,
        ?array $teamMemberIds = null,
        ?array $targetOverrides = null,
    ): Project {
        return DB::transaction(function () use ($project, $attributes, $teamMemberIds, $targetOverrides): Project {
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

            // A new owner must never remain an additional member; re-sync the
            // existing team unless an explicit team list is being applied below.
            if ($ownerChanged && $teamMemberIds === null) {
                $this->syncProjectTeam->handle(
                    $project,
                    $project->teamMembers()->pluck('users.id')->all(),
                );
            }

            if ($teamMemberIds !== null) {
                $project = $this->syncProjectTeam->handle($project, $teamMemberIds);
            }

            // Package change first: it validates the new package and clears the
            // old package's overrides before any intentional new ones apply.
            if (array_key_exists('package_id', $attributes)) {
                $project = $this->changeProjectPackage->handle($project, $attributes['package_id']);
            }

            if ($targetOverrides !== null) {
                $project = $this->syncTargetOverrides->handle($project, $targetOverrides);
            }

            return $project;
        });
    }
}
