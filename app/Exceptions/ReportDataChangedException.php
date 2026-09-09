<?php

namespace App\Exceptions;

use App\Models\MonthlyReport;
use InvalidArgumentException;

/**
 * Thrown when report-relevant source data changed between building the
 * final snapshot / PDF and committing the finalization. Nothing was
 * persisted; the report stays Ready for Review and the month unlocked.
 */
class ReportDataChangedException extends InvalidArgumentException
{
    public static function for(MonthlyReport $report): self
    {
        return new self(sprintf(
            'Report data for %s changed during finalization. Please review and finalize again.',
            $report->monthlyCycle->periodLabel(),
        ));
    }
}
