<?php

namespace App\Actions\Reports;

use App\Enums\ReportSectionStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportSection;
use App\Services\Reports\ReportReadinessService;
use App\Support\Reports\ReportReadiness;
use Illuminate\Support\Facades\DB;

class SyncReportSectionStatusesAction
{
    public function __construct(
        protected ReportReadinessService $readiness,
    ) {}

    /**
     * Copy the live evaluation into monthly_report_sections.status for
     * display. The stored value is a cache: lifecycle gating must always
     * re-run ReportReadinessService. Safe on locked months because it only
     * mirrors derived state and changes no configuration or content.
     */
    public function handle(MonthlyReport $report): ReportReadiness
    {
        return DB::transaction(function () use ($report): ReportReadiness {
            $readiness = $this->readiness->evaluate($report);

            $report->sections()->get()->each(function (MonthlyReportSection $section) use ($readiness): void {
                $evaluated = $readiness->section($section->section_key->value);
                $status = $evaluated?->complete ? ReportSectionStatus::Complete : ReportSectionStatus::Incomplete;

                if ($section->status !== $status) {
                    $section->forceFill(['status' => $status])->save();
                }
            });

            return $readiness;
        });
    }
}
