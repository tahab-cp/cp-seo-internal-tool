<?php

namespace App\Actions\Reports;

use App\Enums\ReportSectionStatus;
use App\Enums\ReportStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\ProjectReportSection;
use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;
use Illuminate\Support\Facades\DB;

class CreateMonthlyReportAction
{
    public function __construct(
        protected EnsureProjectReportSectionsAction $ensureSections,
        protected MonthlyCycleMutationGuard $cycleLock,
    ) {}

    /**
     * Create the month's draft report and snapshot the project's report
     * sections, in one transaction:
     *
     *   1. ensure the project has its report configuration
     *   2. create the draft report
     *   3. copy the project's sections into monthly_report_sections
     *
     * Uniqueness per cycle is guaranteed by the database; a duplicate
     * surfaces as UniqueConstraintViolationException, which
     * EnsureMonthlyReportAction treats as "another process won".
     */
    public function handle(MonthlyCycle $cycle): MonthlyReport
    {
        if ($cycle->isLocked()) {
            throw LockedMonthlyCycleException::for($cycle, 'start a report for it');
        }

        return DB::transaction(function () use ($cycle): MonthlyReport {
            // Row-lock the cycle and re-check: a finalization may have locked it meanwhile.
            $this->cycleLock->lockForWrite($cycle, 'start a report for it');

            $sections = $this->ensureSections->handle($cycle->project);

            $report = $cycle->monthlyReport()->create([
                'status' => ReportStatus::Draft,
                'version' => 1,
            ]);

            $sections->each(fn (ProjectReportSection $section) => $report->sections()->create([
                'section_key' => $section->section_key,
                'title' => $section->title,
                'is_enabled' => $section->is_enabled,
                'is_required' => $section->is_required,
                'sort_order' => $section->sort_order,
                'status' => ReportSectionStatus::Incomplete,
            ]));

            return $report->load('sections');
        });
    }
}
