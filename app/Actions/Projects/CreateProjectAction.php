<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\ActiveUserGuard;
use Illuminate\Support\Facades\DB;

class CreateProjectAction
{
    public function __construct(
        protected SyncProjectTeamAction $syncProjectTeam,
        protected ChangeProjectPackageAction $changeProjectPackage,
        protected SyncProjectTargetOverridesAction $syncTargetOverrides,
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * Create a project for a client, attach its team, assign its package and
     * apply any intentional target overrides.
     *
     * package_id may be null for legacy/migrated records; the Filament form
     * requires a package for projects created through the application.
     * Later milestones extend this workflow with onboarding tasks and the
     * first monthly cycle, as documented in docs/business-rules.md.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $teamMemberIds
     * @param  array<string, int|string>  $targetOverrides  target_key => value
     */
    public function handle(array $attributes, array $teamMemberIds = [], array $targetOverrides = []): Project
    {
        return DB::transaction(function () use ($attributes, $teamMemberIds, $targetOverrides): Project {
            $this->activeUsers->ensureActive([$attributes['primary_seo_user_id'] ?? null], 'the primary SEO owner');

            $project = Project::query()->create([
                'client_id' => $attributes['client_id'],
                'name' => $attributes['name'],
                'website_url' => $attributes['website_url'],
                'target_location' => $attributes['target_location'] ?? null,
                'status' => $attributes['status'] ?? ProjectStatus::Onboarding,
                'start_date' => $attributes['start_date'] ?? null,
                'end_date' => $attributes['end_date'] ?? null,
                'primary_seo_user_id' => $attributes['primary_seo_user_id'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $project = $this->syncProjectTeam->handle($project, $teamMemberIds);

            $project = $this->changeProjectPackage->handle($project, $attributes['package_id'] ?? null);

            if ($targetOverrides !== []) {
                $project = $this->syncTargetOverrides->handle($project, $targetOverrides);
            }

            return $project;
        });
    }
}
