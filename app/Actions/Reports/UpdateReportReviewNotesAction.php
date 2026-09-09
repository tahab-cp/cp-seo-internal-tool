<?php

namespace App\Actions\Reports;

use App\Models\MonthlyReport;
use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateReportReviewNotesAction
{
    public const NOTES_MAX = 10000;

    public function __construct(
        protected MonthlyCycleMutationGuard $cycleLock,
    ) {}

    /**
     * Internal reviewer notes. Never rendered to the client; not part of
     * the client-facing snapshot. Frozen once the report is final.
     */
    public function handle(MonthlyReport $report, ?string $reviewNotes): MonthlyReport
    {
        return DB::transaction(function () use ($report, $reviewNotes): MonthlyReport {
            $this->cycleLock->lockForWrite($report->monthly_cycle_id, 'edit its review notes');

            if ($report->isFinal()) {
                throw new InvalidArgumentException('Review notes cannot change on a final report.');
            }

            $notes = trim((string) $reviewNotes);

            if (mb_strlen($notes) > self::NOTES_MAX) {
                throw new InvalidArgumentException('Review notes may not exceed '.self::NOTES_MAX.' characters.');
            }

            $report->review_notes = $notes === '' ? null : $notes;
            $report->save();

            return $report;
        });
    }
}
