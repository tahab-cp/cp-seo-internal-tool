<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare persistence; tests needing a snapshot should use
 * EnsureMonthlyReportAction. Statuses other than draft are only reachable
 * here until the Milestone 13 workflow exists.
 *
 * @extends Factory<MonthlyReport>
 */
class MonthlyReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_cycle_id' => MonthlyCycle::factory(),
            'status' => ReportStatus::Draft,
            'executive_summary' => null,
            'review_notes' => null,
            'snapshot_json' => null,
            'generated_pdf_path' => null,
            'generated_at' => null,
            'finalized_at' => null,
            'finalized_by' => null,
        ];
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => ['monthly_cycle_id' => $cycle->getKey()]);
    }

    public function status(ReportStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
