<?php

namespace App\Actions\Reports;

use App\Enums\AuditEventType;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportStatus;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportRevision;
use App\Models\User;
use App\Services\Reports\ReportAuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class UnlockMonthlyReportAction
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    public function __construct(
        protected ReportAuditRecorder $audit,
    ) {}

    /**
     * Controlled correction of a FINAL report (Super Admin only), in one
     * transaction under FOR UPDATE on the report row and its cycle row:
     *
     *   1. authorize (reports.unlock)
     *   2. validate the mandatory reason
     *   3. lock report + cycle rows and re-check: report final, cycle locked
     *   4. archive the current final (snapshot, PDF path, finalizer,
     *      timestamps) as an immutable revision carrying this version
     *      number and the reason
     *   5. advance the report to the next version, clear its final-only
     *      fields, set it back to Draft (it must be reviewed again)
     *   6. return the cycle to Reporting and clear locked_at / locked_by
     *   7. record the audit event
     *
     * The previous PDF file is never touched. Narrative (executive
     * summary, section commentary, review notes) is kept as the working
     * content of the correction. A concurrent second unlock sees the
     * report already back in Draft and is refused deterministically; the
     * unique (report, version) index is the final duplicate protection.
     */
    public function handle(MonthlyReport $report, User $actor, ?string $reason): MonthlyReport
    {
        Gate::forUser($actor)->authorize('unlock', $report);

        $reason = $this->validateReason($reason);

        $this->beforeAcquiringLocks($report);

        try {
            return DB::transaction(function () use ($report, $actor, $reason): MonthlyReport {
                /** @var MonthlyReport $locked */
                $locked = MonthlyReport::query()->lockForUpdate()->findOrFail($report->getKey());

                if (! $locked->isFinal()) {
                    throw new InvalidArgumentException('Only a final report can be unlocked for correction.');
                }

                /** @var MonthlyCycle $cycle */
                $cycle = MonthlyCycle::query()->lockForUpdate()->findOrFail($locked->monthly_cycle_id);

                if (! $cycle->isLocked()) {
                    throw new InvalidArgumentException('The reporting month is not locked; there is nothing to unlock.');
                }

                if (! is_array($locked->snapshot_json)) {
                    throw new InvalidArgumentException('The final report has no stored snapshot and cannot be archived.');
                }

                $archivedAt = now();

                $revision = MonthlyReportRevision::query()->create([
                    'monthly_report_id' => $locked->getKey(),
                    'version' => $locked->version,
                    'snapshot_json' => $locked->snapshot_json,
                    'generated_pdf_path' => $locked->generated_pdf_path,
                    'generated_at' => $locked->generated_at,
                    'finalized_at' => $locked->finalized_at,
                    'finalized_by' => $locked->finalized_by,
                    'archived_at' => $archivedAt,
                    'archived_by' => $actor->getKey(),
                    'unlock_reason' => $reason,
                ]);

                $previous = [
                    'superseded_version' => $locked->version,
                    'revision_id' => $revision->getKey(),
                    'previous_finalized_at' => $locked->finalized_at?->toIso8601String(),
                    'previous_finalized_by' => $locked->finalized_by,
                    'previous_generated_pdf_path' => $locked->generated_pdf_path,
                ];

                $locked->forceFill([
                    'version' => $locked->version + 1,
                    'status' => ReportStatus::Draft,
                    'snapshot_json' => null,
                    'generated_pdf_path' => null,
                    'generated_at' => null,
                    'finalized_at' => null,
                    'finalized_by' => null,
                ])->save();

                $cycle->forceFill([
                    'status' => MonthlyCycleStatus::Reporting,
                    'locked_at' => null,
                    'locked_by' => null,
                ])->save();

                $this->audit->record($locked, $actor, AuditEventType::ReportUnlockedForCorrection, $reason, $previous + [
                    'unlocked_at' => $archivedAt->toIso8601String(),
                ]);

                $report->setRawAttributes($locked->getAttributes(), true);
                $report->setRelation('monthlyCycle', $cycle);
                $report->unsetRelation('revisions');

                return $report;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('This report version was already archived by another unlock; reload and review the current state.');
        }
    }

    /**
     * Seam between validation and the locking transaction; a no-op in
     * production. Tests use it to let a competing unlock commit first.
     */
    protected function beforeAcquiringLocks(MonthlyReport $report): void {}

    public function validateReason(?string $reason): string
    {
        $reason = trim(preg_replace('/\s+/u', ' ', (string) $reason) ?? '');

        if ($reason === '' || mb_strlen($reason) < self::REASON_MIN) {
            throw new InvalidArgumentException('A correction reason of at least '.self::REASON_MIN.' characters is required to unlock a final report.');
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidArgumentException('The correction reason may not exceed '.self::REASON_MAX.' characters.');
        }

        return $reason;
    }
}
