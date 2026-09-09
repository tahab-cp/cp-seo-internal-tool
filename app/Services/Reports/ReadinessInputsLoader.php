<?php

namespace App\Services\Reports;

use App\Enums\KeywordStatus;
use App\Enums\MonthlyNoteType;
use App\Models\AuthorityMetric;
use App\Models\Backlink;
use App\Models\Ga4CountryMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\GscPageMetric;
use App\Models\GscQueryMetric;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\MonthlyCycleTarget;
use App\Models\MonthlyNote;
use App\Models\MonthlyReport;
use App\Models\RankingSnapshot;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\Reports\ReadinessInputs;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Loads the readiness inputs for any number of reports with a FIXED set
 * of grouped queries (about a dozen), instead of a dozen queries per
 * report. Each input mirrors exactly one source the readiness rules
 * consult; no rule is decided here.
 */
class ReadinessInputsLoader
{
    /**
     * @param  EloquentCollection<int, MonthlyReport>  $reports  with monthlyCycle loaded (or loadable)
     * @return Collection<int, ReadinessInputs> keyed by report id
     */
    public function forReports(EloquentCollection $reports): Collection
    {
        $reports->loadMissing('monthlyCycle');

        $cycles = $reports->map(fn (MonthlyReport $report): MonthlyCycle => $report->monthlyCycle)->unique('id')->values();
        $cycleIds = $cycles->map(fn (MonthlyCycle $c): int => (int) $c->getKey())->all();
        $projectIds = $cycles->map(fn (MonthlyCycle $c): int => (int) $c->project_id)->unique()->values()->all();

        if ($cycleIds === []) {
            return new Collection;
        }

        $present = fn (string $model): array => array_fill_keys(
            $model::query()->whereIn('monthly_cycle_id', $cycleIds)->distinct()->pluck('monthly_cycle_id')->map(fn ($id): int => (int) $id)->all(),
            true,
        );

        $counts = fn (string $model): Collection => $model::query()->whereIn('monthly_cycle_id', $cycleIds)
            ->selectRaw('monthly_cycle_id, COUNT(*) as total')->groupBy('monthly_cycle_id')->pluck('total', 'monthly_cycle_id');

        $authority = $present(AuthorityMetric::class);
        $gsc = $present(GscMonthlyMetric::class);
        $ga4 = $present(Ga4MonthlyMetric::class);
        $queries = $counts(GscQueryMetric::class);
        $pages = $counts(GscPageMetric::class);
        $countries = $counts(Ga4CountryMetric::class);

        $recommendations = array_fill_keys(
            MonthlyNote::query()->whereIn('monthly_cycle_id', $cycleIds)->ofType(...MonthlyNoteType::recommendationTypes())
                ->distinct()->pluck('monthly_cycle_id')->map(fn ($id): int => (int) $id)->all(),
            true,
        );

        $backlinks = $present(Backlink::class);

        $backlinkTargets = array_fill_keys(
            MonthlyCycleTarget::query()->whereIn('monthly_cycle_id', $cycleIds)
                ->whereIn('target_key', [TargetProgressService::BACKLINKS, TargetProgressService::GUEST_POSTS])
                ->distinct()->pluck('monthly_cycle_id')->map(fn ($id): int => (int) $id)->all(),
            true,
        );

        $activeKeywords = Keyword::query()->whereIn('project_id', $projectIds)->active()
            ->selectRaw('project_id, COUNT(*) as total')->groupBy('project_id')->pluck('total', 'project_id');

        // Active keywords with at least one observation in the cycle (join
        // keeps the "active" qualification identical to the single rule).
        $keywordsWithSnapshot = RankingSnapshot::query()
            ->join('keywords', 'keywords.id', '=', 'ranking_snapshots.keyword_id')
            ->whereIn('ranking_snapshots.monthly_cycle_id', $cycleIds)
            ->where('keywords.status', KeywordStatus::Active->value)
            ->whereNull('keywords.deleted_at')
            ->selectRaw('ranking_snapshots.monthly_cycle_id, COUNT(DISTINCT ranking_snapshots.keyword_id) as total')
            ->groupBy('ranking_snapshots.monthly_cycle_id')
            ->pluck('total', 'monthly_cycle_id');

        return $reports->mapWithKeys(function (MonthlyReport $report) use ($authority, $gsc, $ga4, $queries, $pages, $countries, $recommendations, $backlinks, $backlinkTargets, $activeKeywords, $keywordsWithSnapshot): array {
            $cycle = $report->monthlyCycle;
            $cycleId = (int) $cycle->getKey();

            return [(int) $report->getKey() => new ReadinessInputs(
                cycleId: $cycleId,
                executiveSummary: $report->executive_summary,
                hasAuthorityMetric: isset($authority[$cycleId]),
                hasGscSummary: isset($gsc[$cycleId]),
                hasGa4Summary: isset($ga4[$cycleId]),
                gscQueryCount: (int) ($queries[$cycleId] ?? 0),
                gscPageCount: (int) ($pages[$cycleId] ?? 0),
                ga4CountryCount: (int) ($countries[$cycleId] ?? 0),
                hasRecommendationNote: isset($recommendations[$cycleId]),
                hasBacklinks: isset($backlinks[$cycleId]),
                hasBacklinkTargetSnapshot: isset($backlinkTargets[$cycleId]),
                activeKeywordCount: (int) ($activeKeywords[(int) $cycle->project_id] ?? 0),
                activeKeywordsWithSnapshot: (int) ($keywordsWithSnapshot[$cycleId] ?? 0),
            )];
        });
    }

    public function forReport(MonthlyReport $report): ReadinessInputs
    {
        return $this->forReports(new EloquentCollection([$report]))->get((int) $report->getKey());
    }
}
