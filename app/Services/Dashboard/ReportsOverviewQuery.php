<?php

namespace App\Services\Dashboard;

use App\Enums\ReportStatus;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * The global reports overview query: monthly reports joined to their
 * cycle, project and client, scoped to the projects the user may see.
 * Readiness is evaluated per row by the authoritative service in the
 * presentation layer; nothing here re-defines it.
 */
class ReportsOverviewQuery
{
    /**
     * @return Builder<MonthlyReport>
     */
    public function for(User $user): Builder
    {
        return MonthlyReport::query()
            ->select('monthly_reports.*')
            ->join('monthly_cycles', 'monthly_cycles.id', '=', 'monthly_reports.monthly_cycle_id')
            ->join('projects', 'projects.id', '=', 'monthly_cycles.project_id')
            ->leftJoin('clients', 'clients.id', '=', 'projects.client_id')
            ->whereNull('projects.deleted_at')
            ->whereIn('projects.id', Project::query()->accessibleBy($user)->select('projects.id'))
            ->withCount('revisions')
            ->with(['monthlyCycle.project.client', 'monthlyCycle.project.primarySeoUser', 'finalizedBy', 'sections'])
            ->orderByDesc('monthly_cycles.year')
            ->orderByDesc('monthly_cycles.month')
            ->orderBy('clients.name')
            ->orderBy('projects.name');
    }

    /**
     * @param  Builder<MonthlyReport>  $query
     * @return Builder<MonthlyReport>
     */
    public function period(Builder $query, CyclePeriod $period): Builder
    {
        return $query->where('monthly_cycles.year', $period->year)->where('monthly_cycles.month', $period->month);
    }

    /**
     * @param  Builder<MonthlyReport>  $query
     * @return Builder<MonthlyReport>
     */
    public function corrections(Builder $query): Builder
    {
        return $query
            ->where('monthly_reports.version', '>', 1)
            ->where('monthly_reports.status', '!=', ReportStatus::Final->value)
            ->has('revisions');
    }

    /**
     * @param  Builder<MonthlyReport>  $query
     * @return Builder<MonthlyReport>
     */
    public function search(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('projects.name', 'like', "%{$term}%")
            ->orWhere('clients.name', 'like', "%{$term}%"));
    }
}
