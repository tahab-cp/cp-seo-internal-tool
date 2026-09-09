<?php

namespace App\Services\Dashboard;

use App\Enums\ProjectStatus;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\MonthlyCycles\MonthlyTargetCompletionService;
use App\Services\Reports\ReportReadinessService;
use App\Support\Dashboard\ProjectOperationsRow;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Builds the per-project operational rows the dashboard and the project
 * overview share, for one reporting period, with grouped queries:
 *
 *   - projects (scoped by Project::scopeAccessibleBy) with client + owner
 *   - the period's cycle per project, with its report + revisions
 *   - open / overdue task counts grouped by project
 *   - Monthly Target Completion in one batch
 *   - live report readiness per existing report (the authoritative
 *     ReportReadinessService; reports are few per period)
 *
 * Rendering never creates cycles or mutates anything.
 */
class ProjectOperationsService
{
    public function __construct(
        protected MonthlyTargetCompletionService $completion,
        protected ReportReadinessService $readiness,
    ) {}

    /**
     * Rows for every ACTIVE project the user may see (plus any extra
     * statuses requested), for the period.
     *
     * @param  list<ProjectStatus>|null  $statuses  null = active only
     * @return Collection<int, ProjectOperationsRow> keyed by project id, ordered by client then project name
     */
    public function rowsFor(User $user, CyclePeriod $period, ?array $statuses = null, ?Builder $projects = null): Collection
    {
        $query = ($projects ?? Project::query())
            ->accessibleBy($user)
            ->with(['client', 'primarySeoUser'])
            ->whereIn('status', array_map(fn (ProjectStatus $s): string => $s->value, $statuses ?? [ProjectStatus::Active]))
            ->orderBy(
                Project::query()->getModel()->qualifyColumn('name'),
            );

        return $this->rowsForProjects($query->get(), $period);
    }

    public function rowFor(Project $project, CyclePeriod $period): ProjectOperationsRow
    {
        return $this->rowsForProjects(new Collection([$project]), $period)->first();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return Collection<int, ProjectOperationsRow>
     */
    public function rowsForProjects(Collection $projects, CyclePeriod $period): Collection
    {
        $projectIds = $projects->map(fn (Project $p): int => (int) $p->getKey())->all();

        if ($projectIds === []) {
            return new Collection;
        }

        $cycles = MonthlyCycle::query()
            ->whereIn('project_id', $projectIds)
            ->forPeriod($period)
            ->with(['monthlyReport.revisions', 'monthlyReport.finalizedBy', 'monthlyReport.sections'])
            ->get()
            ->keyBy('project_id');

        $completions = $this->completion->forCycles($cycles->values());

        // Readiness for every existing report in one batch (fixed query count).
        $reports = new EloquentCollection;

        foreach ($cycles as $cycle) {
            if ($cycle->monthlyReport !== null) {
                $reports->push($cycle->monthlyReport->setRelation('monthlyCycle', $cycle));
            }
        }

        $readiness = $this->readiness->evaluateMany($reports);

        $taskCounts = Task::query()
            ->whereIn('project_id', $projectIds)
            ->open()
            ->selectRaw('project_id, COUNT(*) as open_total, SUM(CASE WHEN due_date IS NOT NULL AND due_date < ? THEN 1 ELSE 0 END) as overdue_total', [now()->toDateString()])
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');

        return $projects
            ->sortBy([fn (Project $a, Project $b): int => strcmp($a->client?->name ?? '', $b->client?->name ?? '') ?: strcmp($a->name, $b->name)])
            ->mapWithKeys(function (Project $project) use ($cycles, $completions, $taskCounts, $readiness): array {
                $id = (int) $project->getKey();
                /** @var MonthlyCycle|null $cycle */
                $cycle = $cycles->get($id);
                /** @var MonthlyReport|null $report */
                $report = $cycle?->monthlyReport;

                if ($cycle !== null) {
                    $cycle->setRelation('project', $project);
                }

                if ($report !== null) {
                    $report->setRelation('monthlyCycle', $cycle);
                }

                $counts = $taskCounts->get($id);

                return [$id => new ProjectOperationsRow(
                    project: $project,
                    cycle: $cycle,
                    report: $report,
                    readiness: $report ? $readiness->get((int) $report->getKey()) : null,
                    completion: $cycle ? $completions->get((int) $cycle->getKey()) : null,
                    openTasks: (int) ($counts->open_total ?? 0),
                    overdueTasks: (int) ($counts->overdue_total ?? 0),
                )];
            });
    }
}
