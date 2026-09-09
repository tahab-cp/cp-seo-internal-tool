<?php

namespace Database\Factories;

use App\Models\MonthlyReport;
use App\Models\MonthlyReportRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare persistence for tests; real revisions are archived only by
 * UnlockMonthlyReportAction.
 *
 * @extends Factory<MonthlyReportRevision>
 */
class MonthlyReportRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_report_id' => MonthlyReport::factory(),
            'version' => 1,
            'snapshot_json' => ['schema_version' => 1, 'report' => ['status' => 'final']],
            'generated_pdf_path' => null,
            'generated_at' => now(),
            'finalized_at' => now(),
            'finalized_by' => User::factory(),
            'archived_at' => now(),
            'archived_by' => User::factory(),
            'unlock_reason' => 'Factory revision reason.',
        ];
    }

    public function forReport(MonthlyReport $report, int $version = 1): static
    {
        return $this->state(fn (array $attributes) => ['monthly_report_id' => $report->getKey(), 'version' => $version]);
    }
}
