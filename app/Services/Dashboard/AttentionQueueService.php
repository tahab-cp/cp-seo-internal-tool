<?php

namespace App\Services\Dashboard;

use App\Enums\MonthlyCycleStatus;
use App\Models\User;
use App\Support\Dashboard\AttentionItem;
use App\Support\Dashboard\ProjectOperationsRow;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Reports\SectionReadiness;
use Illuminate\Support\Collection;

/**
 * "Needs attention" for a reporting period: active, accessible projects
 * with an actionable condition, each with explicit reasons in a fixed
 * order. Deterministic: same data, same list.
 */
class AttentionQueueService
{
    public function __construct(
        protected ProjectOperationsService $operations,
    ) {}

    /**
     * @return Collection<int, AttentionItem>
     */
    public function for(User $user, CyclePeriod $period): Collection
    {
        return $this->fromRows($this->operations->rowsFor($user, $period), $period);
    }

    /**
     * @param  Collection<int, ProjectOperationsRow>  $rows
     * @return Collection<int, AttentionItem>
     */
    public function fromRows(Collection $rows, CyclePeriod $period): Collection
    {
        return $rows
            ->map(fn (ProjectOperationsRow $row): ?AttentionItem => $this->itemFor($row, $period))
            ->filter()
            ->sortBy([
                fn (AttentionItem $a, AttentionItem $b): int => $a->rank() <=> $b->rank() ?: strcmp($a->project->name, $b->project->name),
            ])
            ->values();
    }

    protected function itemFor(ProjectOperationsRow $row, CyclePeriod $period): ?AttentionItem
    {
        $reasons = [];

        if ($row->cycle === null) {
            $reasons[] = ['code' => AttentionItem::MISSING_CYCLE, 'label' => 'Missing monthly cycle', 'detail' => 'The active project has no cycle for '.$period->label().'.'];
        }

        $report = $row->report;
        $readiness = $row->readiness;

        if ($report !== null && $report->isReadyForReview()) {
            $reasons[] = ['code' => AttentionItem::READY_FOR_REVIEW, 'label' => 'Ready for review', 'detail' => $report->versionLabel().' awaiting a manager\'s finalization.'];
        }

        if ($report !== null && $report->isCorrection()) {
            $reasons[] = ['code' => AttentionItem::CORRECTION_IN_PROGRESS, 'label' => 'Correction in progress', 'detail' => $report->versionLabel().' '.$report->status->getLabel().' after a previous final was unlocked.'];
        }

        if ($report !== null && $report->isDraft() && $readiness !== null && ! $readiness->isReady()) {
            $missing = $readiness->missing()->map(fn (SectionReadiness $s): string => $s->title)->implode(', ');
            $reasons[] = ['code' => AttentionItem::REPORT_INCOMPLETE, 'label' => 'Report readiness '.$readiness->percentage().'%', 'detail' => 'Missing: '.$missing];
        }

        if ($report === null && $row->cycle !== null && $row->cycle->status === MonthlyCycleStatus::Reporting) {
            $reasons[] = ['code' => AttentionItem::REPORT_NOT_STARTED, 'label' => 'Report not started', 'detail' => 'The cycle is in reporting but no draft report exists.'];
        }

        if ($row->overdueTasks > 0) {
            $reasons[] = ['code' => AttentionItem::OVERDUE_TASKS, 'label' => $row->overdueTasks.' overdue task'.($row->overdueTasks === 1 ? '' : 's'), 'detail' => null];
        }

        if ($reasons === []) {
            return null;
        }

        usort($reasons, fn (array $a, array $b): int => array_search($a['code'], AttentionItem::ORDER, true) <=> array_search($b['code'], AttentionItem::ORDER, true));

        return new AttentionItem(
            project: $row->project,
            cycle: $row->cycle,
            report: $report,
            periodLabel: $period->label(),
            reasons: $reasons,
        );
    }
}
