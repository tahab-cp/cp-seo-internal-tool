<?php

namespace App\Support\Dashboard;

use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\Project;

/**
 * A project/period that needs a person's attention, with explicit,
 * deterministic reasons. No severity score: the order of reasons is fixed
 * and items are ordered by their first reason, then project name.
 */
final readonly class AttentionItem
{
    public const MISSING_CYCLE = 'missing_cycle';

    public const READY_FOR_REVIEW = 'ready_for_review';

    public const CORRECTION_IN_PROGRESS = 'correction_in_progress';

    public const REPORT_INCOMPLETE = 'report_incomplete';

    public const REPORT_NOT_STARTED = 'report_not_started';

    public const OVERDUE_TASKS = 'overdue_tasks';

    /**
     * Fixed display / sort order of reason codes.
     *
     * @var list<string>
     */
    public const ORDER = [
        self::MISSING_CYCLE,
        self::READY_FOR_REVIEW,
        self::CORRECTION_IN_PROGRESS,
        self::REPORT_INCOMPLETE,
        self::REPORT_NOT_STARTED,
        self::OVERDUE_TASKS,
    ];

    /**
     * @param  list<array{code: string, label: string, detail: ?string}>  $reasons
     */
    public function __construct(
        public Project $project,
        public ?MonthlyCycle $cycle,
        public ?MonthlyReport $report,
        public string $periodLabel,
        public array $reasons,
    ) {}

    /**
     * @return list<string>
     */
    public function reasonCodes(): array
    {
        return array_column($this->reasons, 'code');
    }

    public function hasReason(string $code): bool
    {
        return in_array($code, $this->reasonCodes(), true);
    }

    /**
     * Rank of the most important reason (lower is more urgent).
     */
    public function rank(): int
    {
        $ranks = array_map(fn (string $code): int => (int) array_search($code, self::ORDER, true), $this->reasonCodes());

        return $ranks === [] ? PHP_INT_MAX : min($ranks);
    }

    public function title(): string
    {
        return $this->project->name.' — '.$this->periodLabel;
    }
}
