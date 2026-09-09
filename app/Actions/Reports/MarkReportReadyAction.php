<?php

namespace App\Actions\Reports;

use App\Enums\AuditEventType;
use App\Enums\ReportStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\ReportNotReadyException;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;
use App\Services\Reports\ReportAuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class MarkReportReadyAction
{
    public function __construct(
        protected SyncReportSectionStatusesAction $syncStatuses,
        protected MonthlyCycleMutationGuard $cycleLock,
        protected ReportAuditRecorder $audit,
    ) {}

    /**
     * Draft → Ready for Review.
     *
     *   1. authorize the acting user (project access, report not final,
     *      month unlocked)
     *   2. re-evaluate readiness LIVE; refuse while required data is missing
     *   3. synchronise the display statuses from that evaluation
     *   4. under the cycle row lock, re-check and set the status; record
     *      the audit event
     *
     * Idempotent: a report that is already Ready and still complete is
     * returned unchanged (no second audit event). Ready does not freeze
     * anything; finalization re-checks readiness again.
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

        return DB::transaction(function () use ($report, $actor): MonthlyReport {
            $this->cycleLock->lockForWrite($report->monthly_cycle_id, 'change its report status');

            if (! $report->isReadyForReview()) {
                $report->status = ReportStatus::ReadyForReview;
                $report->save();

                $this->audit->record($report, $actor, AuditEventType::ReportMarkedReady);
            }

            return $report;
        });
    }
}
