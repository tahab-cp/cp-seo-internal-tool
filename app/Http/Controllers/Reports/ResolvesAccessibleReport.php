<?php

namespace App\Http\Controllers\Reports;

use App\Models\MonthlyReport;
use App\Models\MonthlyReportRevision;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Report routes take {project}/{report}[/revisions/{revision}] ids. The
 * project is resolved through Project::scopeAccessibleBy() (unrelated
 * projects are a 404 for an SEO Executive), the report must belong to one
 * of that project's cycles, a revision must belong to that report, and the
 * policy is then checked explicitly. Storage paths never appear in URLs.
 */
trait ResolvesAccessibleReport
{
    protected function resolveReport(User $user, int|string $project, int|string $report, string $ability): MonthlyReport
    {
        $project = Project::query()->accessibleBy($user)->findOrFail((int) $project);

        $report = MonthlyReport::query()
            ->whereHas('monthlyCycle', fn ($query) => $query->where('project_id', $project->getKey()))
            ->with('monthlyCycle.project')
            ->findOrFail((int) $report);

        Gate::forUser($user)->authorize($ability, $report);

        return $report;
    }

    protected function resolveRevision(User $user, int|string $project, int|string $report, int|string $revision, string $ability): MonthlyReportRevision
    {
        $report = $this->resolveReport($user, $project, $report, 'viewHistory');

        $revision = $report->revisions()->findOrFail((int) $revision);
        $revision->setRelation('report', $report);

        Gate::forUser($user)->authorize($ability, $revision);

        return $revision;
    }
}
