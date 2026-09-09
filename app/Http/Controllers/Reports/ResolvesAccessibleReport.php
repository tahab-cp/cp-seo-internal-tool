<?php

namespace App\Http\Controllers\Reports;

use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Report routes take {project}/{report} ids. The project is resolved
 * through Project::scopeAccessibleBy() (unrelated projects are a 404 for
 * an SEO Executive) and the report must belong to one of that project's
 * cycles; the policy is then checked explicitly.
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
}
