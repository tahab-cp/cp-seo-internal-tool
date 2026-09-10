<?php

namespace Tests\Feature\Analytics;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectAnalytics;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AuthorityMetric;
use App\Models\Client;
use App\Models\Ga4CountryMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\GscPageMetric;
use App\Models\GscQueryMetric;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned Project → Analytics screen. Values come
 * from the stored metric records; only their placement, formatting and
 * wording are asserted.
 */
class ProjectAnalyticsLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $executive;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create(['name' => 'James Manager']);
        $this->executive = User::factory()->seoExecutive()->create();
        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->ownedBy($this->executive)->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.example.com', 'target_location' => 'Manchester, UK',
        ]);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    protected function url(Project $project, array $extra = []): string
    {
        return ProjectResource::getUrl('analytics', ['record' => $project] + $extra);
    }

    protected function page()
    {
        return Livewire::test(ProjectAnalytics::class, ['record' => $this->project->getRouteKey()]);
    }

    protected function seedFullMonth(): void
    {
        GscMonthlyMetric::factory()->forCycle($this->september)->create(['clicks' => 1245, 'impressions' => 56780, 'ctr' => 2.19, 'average_position' => 18.4, 'entered_by' => $this->manager->id]);
        GscQueryMetric::factory()->forCycle($this->september)->create(['query' => 'interior designers manchester', 'clicks' => 280, 'impressions' => 8400, 'ctr' => 3.33, 'average_position' => 8.2]);
        GscQueryMetric::factory()->forCycle($this->september)->create(['query' => 'kitchen designers manchester', 'clicks' => 190, 'impressions' => 6300, 'ctr' => 3.02, 'average_position' => 14.9]);
        $page = Page::factory()->forProject($this->project)->create(['url' => 'https://www.example.com/interior-design', 'path' => '/interior-design', 'title' => 'Interior Design']);
        GscPageMetric::factory()->forCycle($this->september)->create(['page_url' => 'https://www.example.com/interior-design', 'page_id' => $page->id, 'clicks' => 285, 'impressions' => 9800, 'ctr' => 2.91, 'average_position' => 10.7]);
        GscPageMetric::factory()->forCycle($this->september)->create(['page_url' => 'https://www.example.com/blog/'.str_repeat('very-long-slug-', 20).'end', 'page_id' => null, 'clicks' => 40, 'impressions' => 900, 'ctr' => 4.44, 'average_position' => 22]);
        Ga4MonthlyMetric::factory()->forCycle($this->september)->create(['active_users' => 2850, 'new_users' => 2210, 'sessions' => 3940, 'organic_sessions' => 2420, 'engaged_sessions' => 2980, 'engagement_rate' => 75.63, 'average_engagement_time_seconds' => 94, 'event_count' => 12450, 'key_events' => 186, 'entered_by' => $this->manager->id]);
        Ga4CountryMetric::factory()->forCycle($this->september)->create(['country' => 'United Kingdom', 'active_users' => 2250, 'new_users' => 1700, 'sessions' => 3040, 'engaged_sessions' => 2300, 'engagement_rate' => 77.3, 'event_count' => 9000, 'key_events' => 150]);
        Ga4CountryMetric::factory()->forCycle($this->september)->create(['country' => 'United States', 'active_users' => 260, 'new_users' => 200, 'sessions' => 350, 'engaged_sessions' => 240, 'engagement_rate' => 70, 'event_count' => 1000, 'key_events' => 12]);
        AuthorityMetric::factory()->forCycle($this->september)->create(['moz_domain_authority' => 31, 'moz_linking_root_domains' => 214, 'ahrefs_domain_rating' => 36, 'ahrefs_url_rating' => 28, 'backlinks_count' => 1845, 'referring_domains_count' => 328, 'notes' => null, 'entered_by' => $this->manager->id]);
    }

    public function test_the_workspace_header_module_title_and_month_controls_render(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html));
        $this->assertStringContainsString('BrightNest Interiors • Manchester, UK', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="analytics"|data-project-module="analytics"[^>]*aria-current="page"/', $html), 'Analytics is the active module');
        $this->assertSame(1, preg_match('/data-analytics-header>.*?<h2[^>]*>Analytics<\/h2>.*?Track monthly search, website traffic and authority performance\./s', $html));
        $this->assertStringNotContainsString('Back to project', $html);

        $this->assertStringContainsString('data-selected-cycle="'.$this->september->id.'"', $html);
        $this->assertStringContainsString('data-analytics-cycle-status="open"', $html);
        $this->assertSame(1, preg_match('/<label for="analytics-cycle"[^>]*>Reporting month<\/label>\s*<div style="min-width: 12rem">/s', $html), 'the month selector is compact');
        $this->assertSame(1, preg_match('/<div\b[^>]*data-analytics-overview[^>]*>/s', $html, $overview));
        $this->assertStringContainsString('--cols-xl: repeat(4', $overview[0]);
        foreach (['gsc', 'ga4', 'authority'] as $section) {
            $this->assertStringContainsString('data-analytics-section-link="'.$section.'"', $html);
            $this->assertStringContainsString('id="'.$section.'"', $html);
        }
        $this->assertStringNotContainsString('data-analytics-locked', $html);

        $this->page()->assertActionVisible('importCsv')->assertActionDoesNotExist('viewProject');
    }

    public function test_gsc_summary_queries_and_landing_pages_render_as_cards_and_compact_tables(): void
    {
        $this->seedFullMonth();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        // Overview cards pull from the existing GSC and GA4 rows.
        $this->assertSame(1, preg_match('/data-analytics-overview-card="clicks".*?>1,245</s', $html));
        $this->assertSame(1, preg_match('/data-analytics-overview-card="impressions".*?>56,780</s', $html));
        $this->assertSame(1, preg_match('/data-analytics-overview-card="organic-sessions".*?>2,420</s', $html));
        $this->assertSame(1, preg_match('/data-analytics-overview-card="active-users".*?>2,850</s', $html));

        // Summary cards: formatted for reading, raw values kept in attributes.
        $this->assertSame(1, preg_match('/<div\b[^>]*data-gsc-summary[^>]*>/s', $html, $summary));
        $this->assertStringContainsString('--cols-lg: repeat(4', $summary[0]);
        $this->assertStringContainsString('data-gsc-clicks="1245">1,245<', $html);
        $this->assertStringContainsString('data-gsc-impressions="56780">56,780<', $html);
        $this->assertStringContainsString('data-gsc-ctr="2.19">2.19%<', $html);
        $this->assertStringContainsString('data-gsc-position="18.40">18.4<', $html);
        $this->assertStringContainsString('data-gsc-source="manual"', $html);
        $this->assertStringContainsString('Manual Entry', $html);
        $this->assertStringContainsString('Lower average position is better.', $html);
        $this->assertStringContainsString('Entered by James Manager', $html);

        // Queries: query strongest, numbers right-aligned and formatted.
        $this->assertSame(1, preg_match('/<table[^>]*data-gsc-queries>.*?<\/table>/s', $html, $queries));
        $this->assertSame(1, preg_match('/data-gsc-query="interior designers manchester">.*?>interior designers manchester<.*?>280<.*?>8,400<.*?>3.33%<.*?>8.2</s', $queries[0]));
        $this->assertStringContainsString('text-align: right', $queries[0]);
        $this->assertStringContainsString('Queries generating organic search visibility during this reporting month.', $html);

        // Landing pages: path display, mapped title, full URL as the link target, long URLs truncated.
        $this->assertSame(1, preg_match('/<table[^>]*data-gsc-pages>.*?<\/table>/s', $html, $pages));
        $this->assertSame(1, preg_match('/data-gsc-page="https:\/\/www\.example\.com\/interior-design" data-gsc-page-mapping="\d+">.*?Interior Design.*?<a[^>]*href="https:\/\/www\.example\.com\/interior-design"[^>]*target="_blank".*?\/interior-design.*?>285<.*?>9,800<.*?>2.91%<.*?>10.7</s', $pages[0]));
        $this->assertStringContainsString('…', $pages[0], 'the long URL is truncated');
        $this->assertStringNotContainsString('>/blog/'.str_repeat('very-long-slug-', 20).'end<', $pages[0]);
        $this->assertStringContainsString('overflow-x: auto', $html);
    }

    public function test_ga4_summary_secondary_metrics_and_countries_render(): void
    {
        $this->seedFullMonth();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div\b[^>]*data-ga4-summary[^>]*>/s', $html, $summary));
        $this->assertStringContainsString('--cols-lg: repeat(4', $summary[0]);
        $this->assertStringContainsString('data-ga4-active-users="2850">2,850<', $html);
        $this->assertStringContainsString('data-ga4-sessions="3940">3,940<', $html);
        $this->assertStringContainsString('data-ga4-organic-sessions="2420">2,420<', $html);
        $this->assertStringContainsString('data-ga4-engagement-rate="75.63">75.63%<', $html);
        $this->assertStringContainsString('data-ga4-source="manual"', $html);

        $this->assertStringContainsString('data-ga4-metric="new-users">2,210<', $html);
        $this->assertStringContainsString('data-ga4-metric="engaged-sessions">2,980<', $html);
        $this->assertStringContainsString('data-ga4-metric="engagement-time">1m 34s<', $html);
        $this->assertStringContainsString('data-ga4-metric="event-count">12,450<', $html);
        $this->assertStringContainsString('data-ga4-metric="key-events">186<', $html);

        $this->assertSame(1, preg_match('/<table[^>]*data-ga4-countries>.*?<\/table>/s', $html, $countries));
        $this->assertSame(1, preg_match('/data-ga4-country="United Kingdom">.*?>United Kingdom<.*?>2,250<.*?>1,700<.*?>3,040<.*?>77.3%</s', $countries[0]));
        $this->assertSame(1, preg_match('/data-ga4-country="United States">.*?>70%</s', $countries[0]));
        $this->assertStringNotContainsString('Key events', $countries[0], 'less-used metrics stay out of the country table');
    }

    public function test_authority_renders_grouped_metrics_kept_separate_from_operational_backlinks(): void
    {
        $this->seedFullMonth();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div\b[^>]*data-authority=[^>]*>/s', $html, $authority));
        $this->assertStringContainsString('--cols-md: repeat(3', $authority[0]);
        $this->assertSame(1, preg_match('/data-authority-group="moz">.*?Domain Authority.*?data-authority-da="31">31<.*?Linking root domains.*?>214</s', $html));
        $this->assertSame(1, preg_match('/data-authority-group="ahrefs">.*?Domain Rating.*?data-authority-dr="36.0">36<.*?URL Rating.*?>28</s', $html));
        $this->assertSame(1, preg_match('/data-authority-group="link-profile">.*?Known backlinks.*?data-authority-backlinks="1845">1,845<.*?Referring domains.*?>328</s', $html));
        $this->assertStringContainsString('These are site-wide authority metrics. Monthly link-building work is tracked separately under Backlinks.', $html);
        $this->assertStringContainsString('data-authority-source="manual"', $html);
        $this->assertStringNotContainsString('Backlinks (tool total)', $html);
    }

    public function test_empty_and_partial_states_render_compactly_with_the_existing_actions(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-gsc-empty>.*?No Search Console data yet.*?data-gsc-add/s', $html));
        $this->assertSame(1, preg_match('/data-ga4-empty>.*?No Google Analytics data yet.*?data-ga4-add/s', $html));
        $this->assertSame(1, preg_match('/data-authority-empty>.*?No authority metrics yet.*?data-authority-add/s', $html));
        $this->assertStringContainsString("mountAction('editGscSummary')", $html);
        $this->assertStringContainsString('data-gsc-queries-empty', $html);
        $this->assertStringContainsString('data-ga4-countries-empty', $html);
        $this->assertSame(1, preg_match('/data-analytics-overview-card="clicks".*?>—</s', $html), 'overview cards show a dash without data');

        // Partial: a GSC summary without queries or pages keeps the summary and shows compact empties.
        GscMonthlyMetric::factory()->forCycle($this->september)->create(['clicks' => 900, 'impressions' => 9000, 'ctr' => 10, 'average_position' => 12.5]);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();
        $this->assertStringContainsString('data-gsc-clicks="900">900<', $html);
        $this->assertStringContainsString('data-gsc-ctr="10.00">10%<', $html);
        $this->assertStringContainsString('data-gsc-position="12.50">12.5<', $html);
        $this->assertStringNotContainsString('data-gsc-empty', $html);
        $this->assertStringContainsString('data-gsc-queries-empty', $html);
        $this->assertStringContainsString('data-gsc-pages-empty', $html);
        $this->assertStringNotContainsString('fi-ta-empty-state', $html);

        $this->page()
            ->assertActionVisible('editGscSummary')
            ->assertActionVisible('editGscQueries')
            ->assertActionVisible('editGscPages')
            ->assertActionVisible('editGa4Summary')
            ->assertActionVisible('editGa4Countries')
            ->assertActionVisible('editAuthority')
            ->assertActionDoesNotExist('delete');

        // A project without cycles.
        $bare = Project::factory()->create();
        $this->get($this->url($bare))->assertOk()->assertSee('data-analytics-no-cycles', false)->assertDontSee('data-analytics-overview', false);
    }

    public function test_locked_months_are_flagged_and_hide_every_writing_action(): void
    {
        $this->seedFullMonth();
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('data-analytics-cycle-status="locked"', $html);
        $this->assertStringContainsString('This reporting month is locked. Analytics data is read-only.', $html);
        $this->assertStringContainsString('September 2026 (locked)', $html);
        $this->assertStringContainsString('data-gsc-clicks="1245"', $html, 'figures stay visible');

        $component = $this->page();
        foreach (['editGscSummary', 'editGscQueries', 'editGscPages', 'editGa4Summary', 'editGa4Countries', 'editAuthority'] as $action) {
            $component->assertActionHidden($action);
        }

        // Empty add buttons are also absent for a locked month.
        Ga4MonthlyMetric::query()->delete();
        $this->assertStringNotContainsString('data-ga4-add', $this->get($this->url($this->project))->getContent());
    }

    public function test_visibility_and_navigation_scope_are_unchanged(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get($this->url($this->project))->assertNotFound();

        $this->actingAs($this->executive);
        $this->get($this->url($this->project))->assertOk()->assertSee('data-project-module="analytics"', false);

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();
        foreach (['Analytics', 'Google Search Console', 'Google Analytics'] as $label) {
            $this->assertNotContains($label, $labels, $label.' is never a global navigation item');
        }
    }
}
