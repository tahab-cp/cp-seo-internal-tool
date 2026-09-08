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
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * Create a project for a client and attach its team.
     *
     * Later milestones extend this workflow with package/target resolution,
     * onboarding tasks and the first monthly cycle, as documented in
     * docs/business-rules.md.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $teamMemberIds
     */
    public function handle(array $attributes, array $teamMemberIds = []): Project
    {
        return DB::transaction(function () use ($attributes, $teamMemberIds): Project {
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

            return $this->syncProjectTeam->handle($project, $teamMemberIds);
        });
    }
}
