<?php

namespace App\Actions\Projects;

use App\Actions\Reports\EnsureProjectReportSectionsAction;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\ActiveUserGuard;
use Illuminate\Support\Facades\DB;

/**
 * Project creation for LEGACY MIGRATION only.
 *
 * Same integrity rules as CreateProjectAction (active owner, team sync,
 * package assignment, target overrides, report-section configuration) but
 * WITHOUT the product-time side effects: no current MonthlyCycle is
 * ensured (so today's package targets are never snapshotted into an
 * unrelated month) and no onboarding tasks are generated. Historical
 * months come only from the migration source through
 * CreateHistoricalMonthlyCycleAction. Product code keeps using
 * CreateProjectAction unchanged.
 */
class CreateMigratedProjectAction
{
    public function __construct(
        protected SyncProjectTeamAction $syncProjectTeam,
        protected ChangeProjectPackageAction $changeProjectPackage,
        protected SyncProjectTargetOverridesAction $syncTargetOverrides,
        protected EnsureProjectReportSectionsAction $ensureReportSections,
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * @param  list<int>  $teamMemberIds
     * @param  array<string, int>  $targetOverrides
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
                'status' => $attributes['status'] ?? ProjectStatus::Active,
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

            // Deliberately no EnsureMonthlyCycleAction and no onboarding template.
            $this->ensureReportSections->handle($project);

            return $project;
        });
    }
}
