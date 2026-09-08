<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Services\ActiveUserGuard;

class SyncProjectTeamAction
{
    public function __construct(
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * Replace the project's additional team members.
     *
     * Deterministic rules:
     * - duplicates in the input collapse to one membership;
     * - the primary SEO owner is never stored as an additional member, so
     *   selecting the owner again simply drops them from this list;
     * - existing members that remain keep their descriptive project_role;
     * - every member must be an active user.
     *
     * @param  list<int|string>  $userIds
     */
    public function handle(Project $project, array $userIds): Project
    {
        $ids = collect($userIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $project->primary_seo_user_id !== null
                && $id === (int) $project->primary_seo_user_id)
            ->values()
            ->all();

        $this->activeUsers->ensureActive($ids, 'project team members');

        $project->teamMembers()->sync($ids);

        $project->unsetRelation('teamMembers');

        return $project;
    }
}
