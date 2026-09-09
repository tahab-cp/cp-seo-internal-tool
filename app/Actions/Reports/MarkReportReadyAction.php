<?php

namespace App\Actions\Reports;

use App\Enums\ReportStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\ReportNotReadyException;
use App\Models\MonthlyReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class MarkReportReadyAction
{
    public function __construct(
        protected SyncReportSectionStatusesAction $syncStatuses,
    ) {}

    /**
     * Draft → Ready for Review.
     *
     *   1. authorize the acting user (project access, report not final,
     *      month unlocked)
     *   2. re-evaluate readiness LIVE; refuse while required data is missing
     *   3. synchronise the display statuses from that evaluation
     *   4. set the status
     *
     * Idempotent: a report that is already Ready and still complete is
     * returned unchanged. Ready does not freeze anything; finalization
     * re-checks readiness again.
     */
    public function handle(MonthlyReport $report, User $actor): MonthlyReport
    {
        if ($report->isLocked()) {
            throw LockedMonthlyCycleException::for($report->monthlyCycle, 'change its report status');
        }

        if ($report->isFinal()) {
            throw new InvalidArgumentException('A final report cannot be marked ready for review.');
        }

        Gate::forUser($actor)->authorize('markReady', $report);

        // Synchronised outside the status transaction so the display cache
        // reflects the truth even when the transition is refused.
        $readiness = $this->syncStatuses->handle($report);

        if (! $readiness->isReady()) {
            throw ReportNotReadyException::for($report, $readiness, 'be marked ready for review');
        }

        return DB::transaction(function () use ($report): MonthlyReport {
            if (! $report->isReadyForReview()) {
                $report->status = ReportStatus::ReadyForReview;
                $report->save();
            }

            return $report;
        });
    }
}
