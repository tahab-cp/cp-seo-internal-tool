<?php

namespace App\Exceptions;

use App\Models\MonthlyReport;
use App\Support\Reports\ReportReadiness;
use App\Support\Reports\SectionReadiness;
use InvalidArgumentException;

/**
 * Thrown when a lifecycle transition (Ready for Review, Finalize) is
 * attempted while enabled + required sections are still missing data.
 */
class ReportNotReadyException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly ReportReadiness $readiness,
    ) {
        parent::__construct($message);
    }

    public static function for(MonthlyReport $report, ReportReadiness $readiness, string $operation): self
    {
        $missing = $readiness->missing()->map(fn (SectionReadiness $s): string => $s->title)->implode(', ');

        return new self(sprintf(
            'The %s report is %d%% ready and cannot %s. Missing: %s.',
            $report->monthlyCycle->periodLabel(),
            $readiness->percentage(),
            $operation,
            $missing !== '' ? $missing : 'nothing',
        ), $readiness);
    }
}
