<?php

namespace App\Support\Dashboard;

use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Support\Reports\ReportReadiness;
use App\Support\Targets\MonthlyTargetCompletion;

/**
 * One project's operational picture for a reporting period, as computed
 * by the dashboard services (never mutated by rendering).
 */
final readonly class ProjectOperationsRow
{
    public function __construct(
        public Project $project,
        public ?MonthlyCycle $cycle,
        public ?MonthlyReport $report,
        public ?ReportReadiness $readiness,
        public ?MonthlyTargetCompletion $completion,
        public int $openTasks,
        public int $overdueTasks,
    ) {}

    public function isMissingCycle(): bool
    {
        return $this->cycle === null;
    }

    public function reportStatusLabel(): string
    {
        if ($this->report === null) {
            return $this->cycle === null ? 'No cycle' : 'Not started';
        }

        $label = $this->report->status->getLabel().' · '.$this->report->versionLabel();

        return $this->report->isCorrection() ? $label.' (correction)' : $label;
    }

    public function readinessLabel(): string
    {
        return $this->readiness === null ? '—' : $this->readiness->percentage().'%';
    }

    public function completionLabel(): string
    {
        return $this->completion?->label() ?? '—';
    }
}
