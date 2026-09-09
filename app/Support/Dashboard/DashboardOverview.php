<?php

namespace App\Support\Dashboard;

use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Support\Collection;

/**
 * The role-scoped numbers on the operations home screen.
 */
final readonly class DashboardOverview
{
    /**
     * @param  Collection<int, ProjectOperationsRow>  $rows
     * @param  Collection<int, AttentionItem>  $attention
     * @param  array{open: int, overdue: int, due_today: int, due_this_week: int}  $myWork
     */
    public function __construct(
        public CyclePeriod $period,
        public int $activeProjects,
        public int $missingCycles,
        public int $openTasks,
        public int $overdueTasks,
        public int $reportsReadyForReview,
        public int $reportsDraft,
        public int $reportsFinal,
        public int $reportsNotStarted,
        public Collection $rows,
        public Collection $attention,
        public array $myWork,
    ) {}
}
