<?php

namespace App\Services\Reports;

use App\Enums\ReportSectionKey;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportSection;
use App\Support\Reports\ReadinessInputs;
use App\Support\Reports\ReportReadiness;
use App\Support\Reports\SectionReadiness;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Derives report readiness from the report's snapshotted section
 * configuration and the reporting period's CURRENT source data. Stored
 * monthly_report_sections.status is never consulted; lifecycle actions
 * must always call this service.
 *
 * The rules live here and only here. The facts they consult come from
 * ReadinessInputsLoader, which reads them with a fixed set of grouped
 * queries whether one report or fifty are evaluated.
 *
 * Readiness means "enough data to render the section intentionally". It
 * is not Monthly Target Completion: a missed backlinks or blogs target
 * never blocks a report.
 */
class ReportReadinessService
{
    public function __construct(
        protected ReadinessInputsLoader $inputs,
    ) {}

    /**
     * Single-report evaluation (lifecycle actions, editor, snapshot). The
     * section configuration is always re-read so a caller that has just
     * changed sections on the same instance sees the truth.
     */
    public function evaluate(MonthlyReport $report): ReportReadiness
    {
        return $this->evaluateWith(
            $report,
            $this->inputs->forReport($report),
            $report->sections()->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    /**
     * Batch evaluation for dashboards and overviews: inputs are loaded for
     * every report at once, then the same rules are applied per report.
     *
     * @param  EloquentCollection<int, MonthlyReport>  $reports
     * @return Collection<int, ReportReadiness> keyed by report id
     */
    public function evaluateMany(EloquentCollection $reports): Collection
    {
        if ($reports->isEmpty()) {
            return new Collection;
        }

        $reports->loadMissing('sections');
        $inputs = $this->inputs->forReports($reports);

        return $reports->mapWithKeys(fn (MonthlyReport $report): array => [
            (int) $report->getKey() => $this->evaluateWith($report, $inputs->get((int) $report->getKey())),
        ]);
    }

    /**
     * Applies the rules to one report given already-loaded inputs. Without
     * explicit $sections the report's eager-loaded sections are used.
     *
     * @param  EloquentCollection<int, MonthlyReportSection>|null  $sections
     */
    public function evaluateWith(MonthlyReport $report, ReadinessInputs $inputs, ?EloquentCollection $sections = null): ReportReadiness
    {
        $sections ??= $report->sections->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values();

        return new ReportReadiness(
            $sections->map(fn (MonthlyReportSection $section): SectionReadiness => $this->evaluateSection($section, $inputs))->values(),
        );
    }

    public function evaluateSection(MonthlyReportSection $section, ReadinessInputs $inputs): SectionReadiness
    {
        $key = $section->section_key;
        $complete = $section->is_enabled && $this->isComplete($key, $inputs);

        return new SectionReadiness(
            key: $key,
            title: $section->title,
            enabled: $section->is_enabled,
            required: $section->is_required,
            complete: $complete,
            reason: $complete || ! $section->is_enabled ? null : $this->reason($key, $inputs),
            sortOrder: $section->sort_order,
        );
    }

    /**
     * The V1 completeness rule for one section.
     */
    public function isComplete(ReportSectionKey $key, ReadinessInputs $inputs): bool
    {
        return match ($key) {
            ReportSectionKey::ExecutiveSummary => $inputs->hasExecutiveSummary(),
            ReportSectionKey::SiteAuthority => $inputs->hasAuthorityMetric,
            ReportSectionKey::OrganicSearch => $inputs->hasGscSummary,
            ReportSectionKey::WebsiteTraffic => $inputs->hasGa4Summary,
            ReportSectionKey::TopKeywords => $inputs->gscQueryCount > 0,
            ReportSectionKey::LandingPages => $inputs->gscPageCount > 0,
            ReportSectionKey::AudienceCountry => $inputs->ga4CountryCount > 0,
            ReportSectionKey::Recommendations => $inputs->hasRecommendationNote,
            ReportSectionKey::Rankings => $this->rankingsComplete($inputs),
            ReportSectionKey::Backlinks => $this->backlinksComplete($inputs),
        };
    }

    /**
     * Every ACTIVE tracked keyword needs at least one snapshot in the
     * cycle. No active keywords means nothing can be reported: incomplete.
     */
    protected function rankingsComplete(ReadinessInputs $inputs): bool
    {
        return $inputs->activeKeywordCount > 0 && $inputs->activeKeywordsWithoutSnapshot() === 0;
    }

    /**
     * Complete when the month can render a backlinks story: any backlink
     * record, OR a backlinks / guest posts target snapshot (so a genuine
     * zero-activity month renders as "0 / target"). Target achievement is
     * irrelevant.
     */
    protected function backlinksComplete(ReadinessInputs $inputs): bool
    {
        return $inputs->hasBacklinks || $inputs->hasBacklinkTargetSnapshot;
    }

    protected function reason(ReportSectionKey $key, ReadinessInputs $inputs): string
    {
        if ($key === ReportSectionKey::Rankings) {
            if ($inputs->activeKeywordCount === 0) {
                return 'No active keywords are tracked; add keywords and record their rankings.';
            }

            $missing = $inputs->activeKeywordsWithoutSnapshot();

            return sprintf('%d active keyword%s ha%s no ranking recorded this month.', $missing, $missing === 1 ? '' : 's', $missing === 1 ? 's' : 've');
        }

        return $key->readinessRequirement();
    }
}
