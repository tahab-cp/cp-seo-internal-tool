<?php

namespace App\Actions\Reports;

use App\Exceptions\LockedMonthlyCycleException;
use App\Models\MonthlyReport;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateMonthlyReportDraftAction
{
    public const SUMMARY_MAX = 10000;

    /**
     * Minimal draft editing for Milestone 12: the executive summary. Only
     * a draft report in an unlocked month may change. Status transitions
     * are not exposed here; they arrive with the Milestone 13 workflow.
     *
     * @param  array<string, mixed>  $attributes  executive_summary
     */
    public function handle(MonthlyReport $report, array $attributes): MonthlyReport
    {
        return DB::transaction(function () use ($report, $attributes): MonthlyReport {
            if ($report->isLocked()) {
                throw LockedMonthlyCycleException::for($report->monthlyCycle, 'edit its report');
            }

            if (! $report->isDraft()) {
                throw new InvalidArgumentException('Only a draft report can be edited.');
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
