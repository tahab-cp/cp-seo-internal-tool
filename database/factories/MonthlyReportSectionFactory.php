<?php

namespace Database\Factories;

use App\Enums\ReportSectionKey;
use App\Enums\ReportSectionStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReportSection>
 */
class MonthlyReportSectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ReportSectionKey::Backlinks->defaults() + [
            'monthly_report_id' => MonthlyReport::factory(),
            'status' => ReportSectionStatus::Incomplete,
            'custom_text' => null,
            'settings_json' => null,
        ];
    }

    public function forReport(MonthlyReport $report): static
    {
        return $this->state(fn (array $attributes) => ['monthly_report_id' => $report->getKey()]);
    }

    public function key(ReportSectionKey $key): static
    {
        return $this->state(fn (array $attributes) => $key->defaults());
    }
}
