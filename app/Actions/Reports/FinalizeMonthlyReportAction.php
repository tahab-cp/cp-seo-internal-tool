<?php

namespace App\Actions\Reports;

use App\Enums\AuditEventType;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\ReportDataChangedException;
use App\Exceptions\ReportNotReadyException;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;
use App\Services\Reports\PdfReportGenerator;
use App\Services\Reports\ReportAuditRecorder;
use App\Services\Reports\ReportSnapshotBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

class FinalizeMonthlyReportAction
{
    public function __construct(
        protected SyncReportSectionStatusesAction $syncStatuses,
        protected ReportSnapshotBuilder $snapshotBuilder,
        protected PdfReportGenerator $pdfGenerator,
        protected MonthlyCycleMutationGuard $cycleLock,
        protected ReportAuditRecorder $audit,
    ) {}

    /**
     * Ready for Review → Final (for the report's current version), locking
     * the month. Synchronous, and safe:
     *
     *   1. authorize (reports.finalize + project access)
     *   2. status must be Ready for Review (Final is a deterministic no-op)
     *   3. the month must not already be locked
     *   4. re-evaluate readiness LIVE and refuse if anything is missing
     *   5. build the immutable snapshot from current source data
     *   6. generate the PDF from that snapshot (nothing persisted, no row
     *      locks held while Chromium runs)
     *   7. in one short transaction, holding FOR UPDATE on the report row
     *      AND the MonthlyCycle row (the same row every monthly writer
     *      locks):
     *        - refuse if another finalization already won
     *        - re-check the cycle is still unlocked
     *        - re-run readiness
     *        - rebuild the snapshot and compare fingerprints (optimistic
     *          consistency check)
     *        - persist snapshot_json / pdf path / timestamps / finalizer /
     *          status, lock the MonthlyCycle, record the audit event
     *
     * Because every monthly writer takes the same cycle row lock inside its
     * own transaction, none can commit between the final comparison and
     * the cycle lock: it blocks, then sees the month as locked.
     *
     * If the PDF fails, nothing changes. If anything fails after the PDF
     * was written, the file is removed again. Duplicate calls on a final
     * report return it untouched. Previously archived revisions are never
     * touched.
     */
    public function handle(MonthlyReport $report, User $finalizer): MonthlyReport
    {
        if ($report->isFinal()) {
            return $report;
        }

        if (! $report->isReadyForReview()) {
            throw new InvalidArgumentException('Only a report that is Ready for Review can be finalized.');
        }

        $cycle = $report->monthlyCycle;

        if ($cycle->isLocked()) {
            throw LockedMonthlyCycleException::for($cycle, 'finalize its report');
        }

        Gate::forUser($finalizer)->authorize('finalize', $report);

        $readiness = $this->syncStatuses->handle($report);

        if (! $readiness->isReady()) {
            throw ReportNotReadyException::for($report, $readiness, 'be finalized');
        }

        $finalizedAt = now();
        $snapshot = $this->snapshotBuilder->build($report, $finalizer, $finalizedAt);
        $fingerprint = $this->snapshotBuilder->fingerprint($snapshot);

        // May throw PdfGenerationException: the report and cycle are untouched.
        $pdfPath = $this->pdfGenerator->generate($snapshot, $report);

        try {
            return DB::transaction(function () use ($report, $finalizer, $finalizedAt, $snapshot, $fingerprint, $pdfPath): MonthlyReport {
                /** @var MonthlyReport $locked */
                $locked = MonthlyReport::query()->lockForUpdate()->findOrFail($report->getKey());

                if ($locked->isFinal()) {
                    // A concurrent finalization won; ours is discarded.
                    $this->pdfGenerator->delete($pdfPath);

                    return $locked;
                }

                // The shared monthly-writer lock: held until commit.
                $lockedCycle = $this->cycleLock->lockForWrite($locked->monthly_cycle_id, 'finalize its report');
                $locked->setRelation('monthlyCycle', $lockedCycle);

                // Source data may have moved while Chromium was rendering.
                $readiness = $this->syncStatuses->handle($locked);

                if (! $readiness->isReady()) {
                    throw ReportNotReadyException::for($locked, $readiness, 'be finalized');
                }

                $current = $this->snapshotBuilder->build($locked, $finalizer, $finalizedAt);

                if ($this->snapshotBuilder->fingerprint($current) !== $fingerprint) {
                    throw ReportDataChangedException::for($locked);
                }

                $locked->forceFill([
                    'snapshot_json' => $snapshot,
                    'generated_pdf_path' => $pdfPath,
                    'generated_at' => $finalizedAt,
                    'finalized_at' => $finalizedAt,
                    'finalized_by' => $finalizer->getKey(),
                    'status' => ReportStatus::Final,
                ])->save();

                $lockedCycle->forceFill([
                    'status' => MonthlyCycleStatus::Locked,
                    'locked_at' => $finalizedAt,
                    'locked_by' => $finalizer->getKey(),
                ])->save();

                $this->audit->record($locked, $finalizer, AuditEventType::ReportFinalized, null, [
                    'finalized_at' => $finalizedAt->toIso8601String(),
                    'snapshot_fingerprint' => $fingerprint,
                    'generated_pdf_path' => $pdfPath,
                ]);

                $report->setRawAttributes($locked->getAttributes(), true);
                $report->setRelation('monthlyCycle', $lockedCycle);

                return $report;
            });
        } catch (Throwable $exception) {
            $this->pdfGenerator->delete($pdfPath);

            throw $exception;
        }
    }
}
