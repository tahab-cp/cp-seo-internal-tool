<?php

namespace App\Actions\Reports;

use App\Models\MonthlyReportSection;
use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateReportSectionTextAction
{
    public const TEXT_MAX = 10000;

    public function __construct(
        protected MonthlyCycleMutationGuard $cycleLock,
    ) {}

    /**
     * Optional commentary for one snapshotted section (interpretation,
     * context, explanation of unusual movement). Numbers are never stored
     * here; they always come from the source tables. The section's
     * snapshot configuration (title, enabled, required, order) is
     * untouchable.
     */
    public function handle(MonthlyReportSection $section, ?string $customText): MonthlyReportSection
    {
        return DB::transaction(function () use ($section, $customText): MonthlyReportSection {
            $report = $section->report;

            $this->cycleLock->lockForWrite($report->monthly_cycle_id, 'edit its report');

            if ($report->isFinal()) {
                throw new InvalidArgumentException('A final report cannot be edited.');
            }

            $text = trim((string) $customText);

            if (mb_strlen($text) > self::TEXT_MAX) {
                throw new InvalidArgumentException('Section commentary may not exceed '.self::TEXT_MAX.' characters.');
            }

            $section->custom_text = $text === '' ? null : $text;
            $section->save();

            return $section;
        });
    }
}
