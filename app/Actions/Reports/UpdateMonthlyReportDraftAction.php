<?php

namespace App\Actions\Reports;

use App\Models\MonthlyReport;
use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateMonthlyReportDraftAction
{
    public const SUMMARY_MAX = 10000;

    public function __construct(
        protected MonthlyCycleMutationGuard $cycleLock,
    ) {}

    /**
     * Edit the report's client-facing narrative (the executive summary).
     * Allowed while the report is Draft or Ready for Review and the month
     * is unlocked; a final report is immutable. Status transitions are
     * never accepted here: they belong to MarkReportReadyAction and
     * FinalizeMonthlyReportAction.
     *
     * @param  array<string, mixed>  $attributes  executive_summary
     */
    public function handle(MonthlyReport $report, array $attributes): MonthlyReport
    {
        return DB::transaction(function () use ($report, $attributes): MonthlyReport {
            $this->cycleLock->lockForWrite($report->monthly_cycle_id, 'edit its report');

            if ($report->isFinal()) {
                throw new InvalidArgumentException('A final report cannot be edited.');
            }

            if (array_key_exists('executive_summary', $attributes)) {
                $summary = trim((string) $attributes['executive_summary']);

                if (mb_strlen($summary) > self::SUMMARY_MAX) {
                    throw new InvalidArgumentException('The executive summary may not exceed '.self::SUMMARY_MAX.' characters.');
                }

                $report->executive_summary = $summary === '' ? null : $summary;
            }

            $report->save();

            return $report;
        });
    }
}
