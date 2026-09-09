<?php

namespace App\Services\Dashboard;

use App\Models\Task;
use App\Models\User;
use App\Support\Dashboard\DashboardOverview;
use App\Support\Dashboard\ProjectOperationsRow;
use App\Support\MonthlyCycles\CyclePeriod;

/**
 * The operations home screen for one user and period. Every figure is
 * derived from projects the user may see (Project::scopeAccessibleBy);
 * an SEO Executive's numbers therefore never include unrelated projects.
 *
 * Task conventions (shared with the team workload):
 *   open      = pending / in progress / blocked, not soft-deleted
 *   overdue   = open AND due_date < today
 *   due today = open AND due_date = today
 *   this week = open AND today <= due_date <= end of the calendar week
 *               (so overdue and this-week never overlap)
 */
class DashboardOverviewService
{
    public function __construct(
        protected ProjectOperationsService $operations,
        protected AttentionQueueService $attention,
    ) {}

    public function for(User $user, ?CyclePeriod $period = null): DashboardOverview
    {
        $period ??= CyclePeriod::current();
        $rows = $this->operations->rowsFor($user, $period);

        $reports = $rows->map(fn (ProjectOperationsRow $row) => $row->report)->filter();

        return new DashboardOverview(
            period: $period,
            activeProjects: $rows->count(),
            missingCycles: $rows->filter(fn (ProjectOperationsRow $row): bool => $row->isMissingCycle())->count(),
            openTasks: $rows->sum(fn (ProjectOperationsRow $row): int => $row->openTasks),
            overdueTasks: $rows->sum(fn (ProjectOperationsRow $row): int => $row->overdueTasks),
            reportsReadyForReview: $reports->filter(fn ($r): bool => $r->isReadyForReview())->count(),
            reportsDraft: $reports->filter(fn ($r): bool => $r->isDraft())->count(),
            reportsFinal: $reports->filter(fn ($r): bool => $r->isFinal())->count(),
            reportsNotStarted: $rows->filter(fn (ProjectOperationsRow $row): bool => $row->cycle !== null && $row->report === null)->count(),
            rows: $rows,
            attention: $this->attention->fromRows($rows, $period),
            myWork: $this->myWork($user),
        );
    }

    /**
     * The signed-in user's own assigned tasks on projects they can access.
     *
     * @return array{open: int, overdue: int, due_today: int, due_this_week: int}
     */
    public function myWork(User $user): array
    {
        $today = now()->toDateString();
        $endOfWeek = now()->endOfWeek()->toDateString();

        $row = Task::query()
            ->accessibleBy($user)
            ->assignedTo($user)
            ->open()
            ->selectRaw(
                'COUNT(*) as open_total, '
                .'SUM(CASE WHEN due_date IS NOT NULL AND due_date < ? THEN 1 ELSE 0 END) as overdue_total, '
                .'SUM(CASE WHEN due_date = ? THEN 1 ELSE 0 END) as today_total, '
                .'SUM(CASE WHEN due_date >= ? AND due_date <= ? THEN 1 ELSE 0 END) as week_total',
                [$today, $today, $today, $endOfWeek],
            )
            ->first();

        return [
            'open' => (int) ($row->open_total ?? 0),
            'overdue' => (int) ($row->overdue_total ?? 0),
            'due_today' => (int) ($row->today_total ?? 0),
            'due_this_week' => (int) ($row->week_total ?? 0),
        ];
    }
}
