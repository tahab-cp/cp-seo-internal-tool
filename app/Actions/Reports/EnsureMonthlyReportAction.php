<?php

namespace App\Actions\Reports;

use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

class EnsureMonthlyReportAction
{
    public function __construct(
        protected CreateMonthlyReportAction $createMonthlyReport,
    ) {}

    /**
     * Return the month's report, creating the draft (with its section
     * snapshot) only if missing.
     *
     * Idempotent: an existing report is returned as-is; it is never
     * recreated and its section snapshot is never rebuilt. A concurrent
     * creator losing the unique-key race simply receives the report the
     * other creator inserted (its own partial work was rolled back). If
     * the collision was only on the project's section template (another
     * process initialised it at the same moment) the creation is retried
     * once. A locked month with no report cannot start one.
     */
    public function handle(MonthlyCycle $cycle): MonthlyReport
    {
        $existing = $this->find($cycle);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->createMonthlyReport->handle($cycle);
        } catch (UniqueConstraintViolationException) {
            $winner = $this->find($cycle);

            if ($winner !== null) {
                return $winner;
            }

            try {
                return $this->createMonthlyReport->handle($cycle);
            } catch (UniqueConstraintViolationException) {
                return $this->find($cycle)
                    ?? throw new RuntimeException('Monthly report creation collided but the existing report could not be loaded.');
            }
        }
    }

    protected function find(MonthlyCycle $cycle): ?MonthlyReport
    {
        return $cycle->monthlyReport()->with('sections')->first();
    }
}
