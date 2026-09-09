<?php

namespace Tests\Feature\Dashboard;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Enums\BacklinkStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ReportsOverview;
use App\Models\AuthorityMetric;
use App\Models\Backlink;
use App\Models\Ga4CountryMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\GscPageMetric;
use App\Models\GscQueryMetric;
use App\Models\Keyword;
use App\Models\MonthlyReport;
use App\Models\Package;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\Dashboard\DashboardOverviewService;
use App\Services\Reports\ReportReadinessService;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Readiness stays authoritative in ReportReadinessService, but its inputs
 * are loaded with a fixed set of grouped queries, so evaluating N reports
 * on the dashboard or the reports overview costs the same number of
 * queries as evaluating one.
 */
class ReadinessBatchingTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 10],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4],
        ])->create();
    }

    /**
     * An active project with a current cycle, a draft report and
     * representative source data: half the sections complete, half not,
     * so every rule family is exercised.
     */
    protected function makeProject(int $i): MonthlyReport
    {
        $project = Project::factory()->withPackage($this->package)->create(['name' => "Project {$i}"]);
        $cycle = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
        $report = app(EnsureMonthlyReportAction::class)->handle($cycle);

        app(UpdateMonthlyReportDraftAction::class)->handle($report, ['executive_summary' => "Summary {$i}"]);
        AuthorityMetric::factory()->forCycle($cycle)->create();
        GscMonthlyMetric::factory()->forCycle($cycle)->create();
        GscQueryMetric::factory()->count(2)->forCycle($cycle)->create();
        Backlink::factory()->forCycle($cycle)->status(BacklinkStatus::Live)->create();
        app(CreateMonthlyNoteAction::class)->handle($cycle, ['type' => 'recommendation', 'body' => 'Do more.'], $this->manager);

        if ($i % 2 === 0) {
            Ga4MonthlyMetric::factory()->forCycle($cycle)->create();
            GscPageMetric::factory()->forCycle($cycle)->create();
            Ga4CountryMetric::factory()->forCycle($cycle)->create();
            $keyword = Keyword::factory()->forProject($project)->create();
            RankingSnapshot::factory()->forKeyword($keyword)->forCycle($cycle)->at('2026-09-10 09:00', 4)->create();
        } else {
            Keyword::factory()->count(2)->forProject($project)->create();
        }

        return $report->fresh();
    }

    protected function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_batched_readiness_matches_single_evaluation_for_every_report(): void
    {
        $reports = collect(range(1, 6))->map(fn (int $i) => $this->makeProject($i));

        $batched = app(ReportReadinessService::class)->evaluateMany(MonthlyReport::query()->whereIn('id', $reports->pluck('id'))->with('monthlyCycle')->get());

        foreach ($reports as $report) {
            $single = app(ReportReadinessService::class)->evaluate($report->fresh());

            $this->assertEquals($single->toArray(), $batched->get($report->getKey())->toArray(), "Report {$report->getKey()} must evaluate identically in batch.");
        }

        // Even-numbered projects are fully ready; odd ones miss GA4, pages, and rankings (2 keywords, no snapshots).
        $this->assertTrue($batched->get($reports[1]->getKey())->isReady());
        $this->assertFalse($batched->get($reports[0]->getKey())->isReady());
        $this->assertSame(
            ['website_traffic', 'landing_pages', 'rankings'],
            $batched->get($reports[0]->getKey())->missing()->map(fn ($s) => $s->key->value)->all(),
        );
        $this->assertSame('2 active keywords have no ranking recorded this month.', $batched->get($reports[0]->getKey())->section('rankings')->reason);
    }

    public function test_dashboard_and_reports_overview_query_counts_are_bounded_with_many_reports(): void
    {
        foreach (range(1, 10) as $i) {
            $this->makeProject($i);
        }

        $this->actingAs($this->manager);

        $service10 = $this->countQueries(fn () => app(DashboardOverviewService::class)->for($this->manager));
        $overview10 = $this->countQueries(fn () => Livewire::test(ReportsOverview::class)->assertOk());
        $dashboard10 = $this->countQueries(fn () => Livewire::test(Dashboard::class)->assertOk());

        foreach (range(11, 20) as $i) {
            $this->makeProject($i);
        }

        $service20 = $this->countQueries(fn () => app(DashboardOverviewService::class)->for($this->manager));
        $overview20 = $this->countQueries(fn () => Livewire::test(ReportsOverview::class)->assertOk());
        $dashboard20 = $this->countQueries(fn () => Livewire::test(Dashboard::class)->assertOk());

        // Ten more reports must not add ten (or more) readiness query sets.
        $this->assertLessThanOrEqual($service10 + 2, $service20, "Dashboard service queries grew from {$service10} to {$service20}.");
        $this->assertLessThanOrEqual($overview10 + 4, $overview20, "Reports overview queries grew from {$overview10} to {$overview20}.");
        $this->assertLessThanOrEqual($dashboard10 + 6, $dashboard20, "Dashboard page queries grew from {$dashboard10} to {$dashboard20}.");

        // Sanity: far below one readiness query set per report.
        $this->assertLessThan(60, $service20, "Dashboard service issued {$service20} queries for 20 reports.");
    }
}
