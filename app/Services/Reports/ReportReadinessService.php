<?php

namespace App\Services\Reports;

use App\Enums\MonthlyNoteType;
use App\Enums\ReportSectionKey;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportSection;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\Reports\ReportReadiness;
use App\Support\Reports\SectionReadiness;

/**
 * Derives report readiness from the report's snapshotted section
 * configuration and the reporting period's CURRENT source data. Stored
 * monthly_report_sections.status is never consulted; lifecycle actions
 * must always call this service.
 *
 * Readiness means "enough data to render the section intentionally". It
 * is not Monthly Target Completion: a missed backlinks or blogs target
 * never blocks a report.
 */
class ReportReadinessService
{
    public function evaluate(MonthlyReport $report): ReportReadiness
    {
        $cycle = $report->monthlyCycle;

        $sections = $report->sections()
            ->get()
            ->map(fn (MonthlyReportSection $section): SectionReadiness => $this->evaluateSection($section, $cycle))
            ->values();

        return new ReportReadiness($sections);
    }

    public function evaluateSection(MonthlyReportSection $section, MonthlyCycle $cycle): SectionReadiness
    {
        $key = $section->section_key;
        $complete = $section->is_enabled && $this->isComplete($key, $cycle, $section->report);

        return new SectionReadiness(
            key: $key,
            title: $section->title,
            enabled: $section->is_enabled,
            required: $section->is_required,
            complete: $complete,
            reason: $complete || ! $section->is_enabled ? null : $this->reason($key, $cycle),
            sortOrder: $section->sort_order,
        );
    }

    /**
     * The V1 completeness rule for one section, evaluated against live data.
     */
    public function isComplete(ReportSectionKey $key, MonthlyCycle $cycle, ?MonthlyReport $report = null): bool
    {
        return match ($key) {
            ReportSectionKey::ExecutiveSummary => ($report ?? $cycle->monthlyReport)?->hasExecutiveSummary() ?? false,
            ReportSectionKey::SiteAuthority => $cycle->authorityMetric()->exists(),
            ReportSectionKey::OrganicSearch => $cycle->gscMonthlyMetric()->exists(),
            ReportSectionKey::WebsiteTraffic => $cycle->ga4MonthlyMetric()->exists(),
            ReportSectionKey::TopKeywords => $cycle->gscQueryMetrics()->exists(),
            ReportSectionKey::LandingPages => $cycle->gscPageMetrics()->exists(),
            ReportSectionKey::AudienceCountry => $cycle->ga4CountryMetrics()->exists(),
            ReportSectionKey::Recommendations => $cycle->monthlyNotes()->ofType(...MonthlyNoteType::recommendationTypes())->exists(),
            ReportSectionKey::Rankings => $this->rankingsComplete($cycle),
            ReportSectionKey::Backlinks => $this->backlinksComplete($cycle),
        };
    }

    /**
     * Every ACTIVE tracked keyword needs at least one snapshot in the
     * cycle. No active keywords means nothing can be reported: incomplete.
     */
    protected function rankingsComplete(MonthlyCycle $cycle): bool
    {
        $active = Keyword::query()->where('project_id', $cycle->project_id)->active();

        if (! $active->exists()) {
            return false;
        }

        return ! (clone $active)
            ->whereDoesntHave('rankingSnapshots', fn ($query) => $query->where('monthly_cycle_id', $cycle->getKey()))
            ->exists();
    }

    /**
     * Complete when the month can render a backlinks story: any backlink
     * record, OR a backlinks / guest posts target snapshot (so a genuine
     * zero-activity month renders as "0 / target"). Target achievement is
     * irrelevant.
     */
    protected function backlinksComplete(MonthlyCycle $cycle): bool
    {
        if ($cycle->backlinks()->exists()) {
            return true;
        }

        return $cycle->targets()
            ->whereIn('target_key', [TargetProgressService::BACKLINKS, TargetProgressService::GUEST_POSTS])
            ->exists();
    }

    protected function reason(ReportSectionKey $key, MonthlyCycle $cycle): string
    {
        if ($key === ReportSectionKey::Rankings) {
            $active = Keyword::query()->where('project_id', $cycle->project_id)->active();

            if (! $active->exists()) {
                return 'No active keywords are tracked; add keywords and record their rankings.';
            }

            $missing = (clone $active)
                ->whereDoesntHave('rankingSnapshots', fn ($query) => $query->where('monthly_cycle_id', $cycle->getKey()))
                ->count();

            return sprintf('%d active keyword%s ha%s no ranking recorded this month.', $missing, $missing === 1 ? '' : 's', $missing === 1 ? 's' : 've');
        }

        return $key->readinessRequirement();
    }
}
