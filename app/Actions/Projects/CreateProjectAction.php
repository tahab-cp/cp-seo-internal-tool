<?php

namespace App\Actions\Projects;

use App\Actions\MonthlyCycles\EnsureMonthlyCycleAction;
use App\Actions\Reports\EnsureProjectReportSectionsAction;
use App\Actions\Tasks\GenerateOnboardingTasksAction;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ActiveUserGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateProjectAction
{
    public function __construct(
        protected SyncProjectTeamAction $syncProjectTeam,
        protected ChangeProjectPackageAction $changeProjectPackage,
        protected SyncProjectTargetOverridesAction $syncTargetOverrides,
        protected GenerateOnboardingTasksAction $generateOnboardingTasks,
        protected EnsureMonthlyCycleAction $ensureMonthlyCycle,
        protected EnsureProjectReportSectionsAction $ensureReportSections,
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * The project creation workflow, in one transaction:
     *
     *   1. create the project
     *   2. attach the team
     *   3. assign the package
     *   4. apply intentional target overrides
     *   5. optionally generate the onboarding checklist (after the owner,
     *      package and overrides are final so assignment is correct)
     *   6. ensure the current monthly cycle (ACTIVE projects only)
     *   7. initialise the default report section configuration
     *
     * Any failure, including onboarding generation, rolls everything back.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $teamMemberIds
     * @param  array<string, int|string>  $targetOverrides  target_key => value
     * @param  TaskTemplate|null  $onboardingTemplate  active template to generate tasks from
     * @param  User|null  $creator  recorded as the tasks' creator; defaults to the authenticated user
     */
    public function handle(
        array $attributes,
        array $teamMemberIds = [],
        array $targetOverrides = [],
        ?TaskTemplate $onboardingTemplate = null,
        ?User $creator = null,
    ): Project {
        return DB::transaction(function () use ($attributes, $teamMemberIds, $targetOverrides, $onboardingTemplate, $creator): Project {
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

            if ($onboardingTemplate !== null) {
                $creator ??= auth()->user();

                if (! $creator instanceof User) {
                    throw new InvalidArgumentException('Onboarding generation needs a creator user.');
                }

                $this->generateOnboardingTasks->handle($project, $onboardingTemplate, $creator);
            }

            if ($project->status === ProjectStatus::Active) {
                $this->ensureMonthlyCycle->handle($project);
            }

            $this->ensureReportSections->handle($project);

            return $project;
        });
    }
}
