<?php

namespace Tests\Support;

use App\Actions\Analytics\SaveAuthorityMetricsAction;
use App\Actions\Analytics\SaveGa4CountryMetricsAction;
use App\Actions\Analytics\SaveGa4MonthlyMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Models\Client;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\Package;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;

/**
 * A September 2026 project with every report section satisfied, so
 * workflow tests can start from a report that is 100% ready.
 */
trait BuildsCompleteReports
{
    protected User $manager;

    protected Package $package;

    protected Project $project;

    protected MonthlyCycle $cycle;

    protected MonthlyReport $report;

    protected Keyword $keyword;

    protected function buildCompleteReport(?User $owner = null): MonthlyReport
    {
        $this->manager = User::factory()->seoManager()->create(['name' => 'Morgan Manager']);
        $this->package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 5],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 6],
        ])->create(['name' => 'Growth']);

        $client = Client::factory()->create(['name' => 'Casa Botanica Ltd', 'company_name' => 'Casa Botanica Holdings']);
        $factory = Project::factory()->withPackage($this->package)->for($client);
        $this->project = ($owner ? $factory->ownedBy($owner) : $factory)->create([
            'name' => 'Casa Botanica',
            'website_url' => 'https://casabotanica.example',
            'target_location' => 'Marbella, Spain',
        ]);

        $this->cycle = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->report = app(EnsureMonthlyReportAction::class)->handle($this->cycle);

        app(UpdateMonthlyReportDraftAction::class)->handle($this->report, ['executive_summary' => 'A strong month with steady organic growth.']);

        app(SaveAuthorityMetricsAction::class)->handle($this->cycle, ['moz_domain_authority' => 42, 'moz_linking_root_domains' => 310, 'ahrefs_domain_rating' => 48.5, 'ahrefs_url_rating' => 31, 'backlinks_count' => 12500, 'referring_domains_count' => 640], $this->manager);
        app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle, ['clicks' => 1200, 'impressions' => 48000, 'ctr' => 2.5, 'average_position' => 14.3], $this->manager);
        app(SaveGa4MonthlyMetricsAction::class)->handle($this->cycle, ['active_users' => 5000, 'new_users' => 3200, 'sessions' => 7400, 'organic_sessions' => 4100, 'engaged_sessions' => 4600, 'engagement_rate' => 62.16, 'average_engagement_time_seconds' => 95, 'event_count' => 30000, 'key_events' => 120], $this->manager);
        app(SaveGscQueryMetricsAction::class)->handle($this->cycle, [
            ['query' => 'villa rentals marbella', 'clicks' => 120, 'impressions' => 4000, 'ctr' => 3, 'average_position' => 6.2],
            ['query' => 'luxury villas', 'clicks' => 80, 'impressions' => 2600, 'ctr' => 3.08, 'average_position' => 9.1],
        ], $this->manager);
        app(SaveGscPageMetricsAction::class)->handle($this->cycle, [
            ['page_url' => 'https://casabotanica.example/villas', 'clicks' => 200, 'impressions' => 5000, 'ctr' => 4, 'average_position' => 5.1],
            ['page_url' => 'https://casabotanica.example/blog/summer', 'clicks' => 50, 'impressions' => 900],
        ], $this->manager);
        app(SaveGa4CountryMetricsAction::class)->handle($this->cycle, [
            ['country' => 'United Kingdom', 'active_users' => 3000, 'sessions' => 4500, 'engagement_rate' => 62.2],
            ['country' => 'Spain', 'active_users' => 900, 'sessions' => 1200],
        ], $this->manager);

        app(CreateMonthlyNoteAction::class)->handle($this->cycle, ['type' => 'win', 'title' => 'Top spot', 'body' => 'Ranked #1 for villa rentals marbella.'], $this->manager);
        app(CreateMonthlyNoteAction::class)->handle($this->cycle, ['type' => 'challenge', 'body' => 'Site migration slipped a week.'], $this->manager);
        app(CreateMonthlyNoteAction::class)->handle($this->cycle, ['type' => 'recommendation', 'body' => 'Add FAQ schema to villa pages.'], $this->manager);
        app(CreateMonthlyNoteAction::class)->handle($this->cycle, ['type' => 'next_month_focus', 'body' => 'Publish the pricing guide.'], $this->manager);

        $this->keyword = Keyword::factory()->forProject($this->project)->create(['keyword' => 'villa rentals marbella', 'location' => 'Marbella']);
        RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->cycle)->at('2026-09-02 09:00', 24)->create();
        RankingSnapshot::factory()->forKeyword($this->keyword)->forCycle($this->cycle)->at('2026-09-28 09:00', 8)->create();

        app(CreateBacklinkAction::class)->handle($this->project, [
            'monthly_cycle_id' => $this->cycle->id,
            'published_url' => 'https://partner.example/guest-post',
            'anchor_text' => 'villa rentals',
            'type' => 'guest_post',
            'status' => 'live',
            'published_date' => '2026-09-10',
            'domain_authority' => 45,
        ], $this->manager);

        return $this->report->fresh();
    }
}
