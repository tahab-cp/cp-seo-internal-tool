<?php

namespace App\Actions\Reports;

use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\ReportDataChangedException;
use App\Exceptions\ReportNotReadyException;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Services\Reports\PdfReportGenerator;
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
    ) {}

    /**
     * Ready for Review → Final, locking the month. Synchronous, and safe:
     *
     *   1. authorize (reports.finalize + project access)
     *   2. status must be Ready for Review (Final is a deterministic no-op)
     *   3. the month must not already be locked
     *   4. re-evaluate readiness LIVE and refuse if anything is missing
     *   5. build the immutable snapshot from current source data
     *   6. generate the PDF from that snapshot (nothing persisted yet)
     *   7. in one transaction, under a row lock on the report:
     *        - refuse if another finalization already won
     *        - re-run readiness again
     *        - rebuild the snapshot and compare fingerprints: if any
     *          report-relevant source data changed while Chromium was
     *          running, abort (optimistic consistency check)
     *        - persist snapshot_json / pdf path / timestamps / finalizer /
     *          status, and lock the MonthlyCycle
     *
     * If the PDF fails, nothing changes. If anything fails after the PDF
     * was written (including the consistency check), the file is removed
     * again. Duplicate calls on a final report return it untouched.
     *
     * The remaining window is the few milliseconds between the re-check
     * and the commit; the cycle lock then makes every monthly module
     * read-only through the existing guards.
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
            return DB::transaction(function () use ($report, $cycle, $finalizer, $finalizedAt, $snapshot, $fingerprint, $pdfPath): MonthlyReport {
                /** @var MonthlyReport $locked */
                $locked = MonthlyReport::query()->lockForUpdate()->findOrFail($report->getKey());

                if ($locked->isFinal()) {
                    // A concurrent finalization won; ours is discarded.
                    $this->pdfGenerator->delete($pdfPath);

                    return $locked;
                }

                if ($locked->monthlyCycle->isLocked()) {
                    throw LockedMonthlyCycleException::for($locked->monthlyCycle, 'finalize its report');
                }

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

                $cycle->forceFill([
                    'status' => MonthlyCycleStatus::Locked,
                    'locked_at' => $finalizedAt,
                    'locked_by' => $finalizer->getKey(),
                ])->save();

                $report->setRawAttributes($locked->getAttributes(), true);
                $report->setRelation('monthlyCycle', $cycle);

                return $report;
            });
        } catch (Throwable $exception) {
            $this->pdfGenerator->delete($pdfPath);

            throw $exception;
        }
    }
}
