<?php

namespace App\Services\Dashboard;

use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Dashboard\TeamWorkloadRow;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Organisation-wide workload indicators (Super Admin / SEO Manager only).
 *
 * Scope conventions:
 *   - users: active accounts with a role, ordered by name; deactivated
 *     users are excluded because their work must be reassigned, not
 *     tracked as workload;
 *   - projects: Project.status = active only (not soft-deleted);
 *   - tasks: assigned to the user on active projects, open (pending / in
 *     progress / blocked), not soft-deleted;
 *   - overdue = due_date < today; due this week = today <= due_date <=
 *     end of the calendar week (mutually exclusive with overdue);
 *   - reports in preparation = Draft / Ready reports for the current
 *     period on active projects the user owns or belongs to.
 *
 * Counts are indicators, not capacity or performance.
 */
class TeamWorkloadService
{
    /**
     * @return Collection<int, TeamWorkloadRow>
     */
    public function rows(?CyclePeriod $period = null): Collection
    {
        $period ??= CyclePeriod::current();
        $active = ProjectStatus::Active->value;

        $users = User::query()->active()->with('roles')->whereHas('roles')->orderBy('name')->get();
        $ids = $users->modelKeys();

        if ($ids === []) {
            return new Collection;
        }

        $primary = Project::query()->where('status', $active)->whereIn('primary_seo_user_id', $ids)
            ->selectRaw('primary_seo_user_id as user_id, COUNT(*) as total')->groupBy('primary_seo_user_id')->pluck('total', 'user_id');

        $team = DB::table('project_user')
            ->join('projects', 'projects.id', '=', 'project_user.project_id')
            ->whereNull('projects.deleted_at')
            ->where('projects.status', $active)
            ->whereIn('project_user.user_id', $ids)
            ->selectRaw('project_user.user_id, COUNT(*) as total')->groupBy('project_user.user_id')->pluck('total', 'user_id');

        $today = now()->toDateString();
        $endOfWeek = now()->endOfWeek()->toDateString();

        $tasks = Task::query()
            ->whereIn('assigned_user_id', $ids)
            ->open()
            ->whereHas('project', fn ($q) => $q->where('status', $active))
            ->selectRaw(
                'assigned_user_id, COUNT(*) as open_total, '
                .'SUM(CASE WHEN due_date IS NOT NULL AND due_date < ? THEN 1 ELSE 0 END) as overdue_total, '
                .'SUM(CASE WHEN due_date >= ? AND due_date <= ? THEN 1 ELSE 0 END) as week_total',
                [$today, $today, $endOfWeek],
            )
            ->groupBy('assigned_user_id')
            ->get()
            ->keyBy('assigned_user_id');

        // Draft / Ready reports for the period on active projects, attributed to
        // the owner and every team member of the project.
        $reports = MonthlyReport::query()
            ->whereIn('status', [ReportStatus::Draft->value, ReportStatus::ReadyForReview->value])
            ->whereHas('monthlyCycle', fn ($q) => $q->forPeriod($period)->whereHas('project', fn ($p) => $p->where('status', $active)))
            ->with('monthlyCycle.project.teamMembers:id')
            ->get();

        $reportsByUser = [];

        foreach ($reports as $report) {
            $project = $report->monthlyCycle->project;
            $people = array_unique(array_filter([$project->primary_seo_user_id, ...$project->teamMembers->modelKeys()]));

            foreach ($people as $userId) {
                $reportsByUser[$userId] = ($reportsByUser[$userId] ?? 0) + 1;
            }
        }

        return $users->map(fn (User $user): TeamWorkloadRow => new TeamWorkloadRow(
            user: $user,
            primaryProjects: (int) ($primary[$user->getKey()] ?? 0),
            teamProjects: (int) ($team[$user->getKey()] ?? 0),
            openTasks: (int) ($tasks->get($user->getKey())?->open_total ?? 0),
            overdueTasks: (int) ($tasks->get($user->getKey())?->overdue_total ?? 0),
            dueThisWeekTasks: (int) ($tasks->get($user->getKey())?->week_total ?? 0),
            reportsInPreparation: (int) ($reportsByUser[$user->getKey()] ?? 0),
        ))->values();
    }
}
