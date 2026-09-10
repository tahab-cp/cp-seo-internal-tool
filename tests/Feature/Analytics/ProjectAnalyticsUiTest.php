<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SaveGa4MonthlyMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Filament\Resources\Projects\Pages\ProjectAnalytics;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectAnalyticsUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected MonthlyCycle $october;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));

        $this->actingAs($this->manager);
    }

    protected function page()
    {
        return Livewire::test(ProjectAnalytics::class, ['record' => $this->project->getRouteKey()]);
    }

    public function test_month_selector_shows_the_selected_months_own_analytics(): void
    {
        app(SaveGscMonthlyMetricsAction::class)->handle($this->september, ['clicks' => 900, 'impressions' => 9000, 'ctr' => 10, 'average_position' => 12.5], $this->manager);
        app(SaveGa4MonthlyMetricsAction::class)->handle($this->september, ['active_users' => 4500, 'sessions' => 6000, 'engagement_rate' => 61.5], $this->manager);

        // Defaults to the current period (October), which has nothing yet.
        $this->get(ProjectResource::getUrl('analytics', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('data-selected-cycle="'.$this->october->id.'"', false)
            ->assertSee('No Search Console data yet')
            ->assertSee('No Google Analytics data yet')
            ->assertSee('No authority metrics yet')
            ->assertSee('Manual Entry');

        // Deep link and live switch both select September.
        $this->get(ProjectResource::getUrl('analytics', ['record' => $this->project, 'cycle' => $this->september->id]))
            ->assertOk()
            ->assertSee('data-selected-cycle="'.$this->september->id.'"', false)
            ->assertSee('data-gsc-clicks="900"', false)
            ->assertSee('data-gsc-ctr="10.00"', false)
            ->assertSee('data-gsc-position="12.50"', false)
            ->assertSee('data-ga4-active-users="4500"', false)
            ->assertSee('data-ga4-engagement-rate="61.50"', false);

        $this->page()
            ->assertSet('selectedCycle', (string) $this->october->id)
            ->assertSee('No Search Console data yet')
            ->set('selectedCycle', (string) $this->september->id)
            ->assertSee('data-gsc-clicks="900"', false)
            ->assertDontSee('No Search Console data yet')
            ->set('selectedCycle', (string) $this->october->id)
            ->assertSee('No Search Console data yet')
            ->assertDontSee('data-gsc-clicks="900"', false);

        // A crafted cycle id in the URL falls back to the default month.
        $this->get(ProjectResource::getUrl('analytics', ['record' => $this->project, 'cycle' => 999999]))
            ->assertOk()
            ->assertSee('data-selected-cycle="'.$this->october->id.'"', false);
    }

    public function test_forms_validate_and_save_each_section(): void
    {
        $page = Page::factory()->forProject($this->project)->create(['url' => 'https://site.example/villas']);

        $this->page()
            ->callAction('editGscSummary', data: ['clicks' => -1, 'impressions' => '', 'ctr' => 120, 'average_position' => 0])
            ->assertHasFormErrors(['clicks', 'impressions', 'ctr', 'average_position']);

        $this->page()
            ->callAction('editGscSummary', data: ['clicks' => 1200, 'impressions' => 48000, 'ctr' => 2.5, 'average_position' => 14.3])
            ->assertHasNoFormErrors()
            ->assertNotified('Search Console summary saved')
            ->callAction('editGscQueries', data: ['rows' => [
                ['query' => 'villa rentals', 'clicks' => 120, 'impressions' => 4000, 'ctr' => 3, 'average_position' => 6.2],
                ['query' => 'luxury villas', 'clicks' => 80, 'impressions' => 2600],
            ]])
            ->assertHasNoFormErrors()
            ->assertNotified('Top queries saved')
            ->callAction('editGscPages', data: ['rows' => [
                ['page_url' => 'https://site.example/villas', 'page_id' => $page->id, 'clicks' => 200, 'impressions' => 5000, 'ctr' => 4, 'average_position' => 5.1],
                ['page_url' => 'https://site.example/blog/summer', 'clicks' => 50, 'impressions' => 900],
            ]])
            ->assertHasNoFormErrors()
            ->assertNotified('Landing pages saved')
            ->callAction('editGa4Summary', data: ['active_users' => 5000, 'new_users' => 3200, 'sessions' => 7400, 'organic_sessions' => 4100, 'engaged_sessions' => 4600, 'engagement_rate' => 62.16, 'average_engagement_time_seconds' => 95, 'event_count' => 30000, 'key_events' => 120])
            ->assertHasNoFormErrors()
            ->assertNotified('Google Analytics summary saved')
            ->callAction('editGa4Countries', data: ['rows' => [
                ['country' => 'United Kingdom', 'active_users' => 3000, 'sessions' => 4500, 'engagement_rate' => 62.2],
                ['country' => 'Spain', 'active_users' => 900],
            ]])
            ->assertHasNoFormErrors()
            ->assertNotified('Audience by country saved')
            ->callAction('editAuthority', data: ['moz_domain_authority' => 42, 'moz_linking_root_domains' => 310, 'ahrefs_domain_rating' => 48.5, 'ahrefs_url_rating' => 31, 'backlinks_count' => 12500, 'referring_domains_count' => 640, 'notes' => 'Checked 5 Oct'])
            ->assertHasNoFormErrors()
            ->assertNotified('Site authority saved')
            ->assertSee('data-gsc-clicks="1200"', false)
            ->assertSee('data-gsc-query="villa rentals"', false)
            ->assertSee('data-gsc-page="https://site.example/villas"', false)
            ->assertSee('data-gsc-page-mapping="'.$page->id.'"', false)
            ->assertSee('data-ga4-sessions="7400"', false)
            ->assertSee('data-ga4-country="United Kingdom"', false)
            ->assertSee('data-authority-da="42"', false)
            ->assertSee('data-authority-backlinks="12500"', false)
            ->assertSee('Checked 5 Oct');

        $this->assertSame($this->manager->id, $this->october->gscMonthlyMetric()->value('entered_by'));
        $this->assertSame(2, $this->october->gscQueryMetrics()->count());
        $this->assertSame(2, $this->october->gscPageMetrics()->count());
        $this->assertSame(2, $this->october->ga4CountryMetrics()->count());
        $this->assertSame(0, $this->september->gscQueryMetrics()->count());

        // Re-opening the summary form is pre-filled from the saved row, and saving again updates it.
        $this->page()
            ->mountAction('editGscSummary')
            ->assertSchemaStateSet(['clicks' => 1200, 'impressions' => 48000])
            ->callMountedAction()
            ->assertNotified('Search Console summary saved');

        $this->assertSame(1, $this->october->gscMonthlyMetric()->count());

        // Editing the detail set: same identity updates, omitted row removed, duplicate rejected by the form.
        $this->page()
            ->callAction('editGscQueries', data: ['rows' => [
                ['query' => 'Villa Rentals', 'clicks' => 150, 'impressions' => 4200],
            ]])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $this->october->gscQueryMetrics()->count());
        $this->assertSame(150, $this->october->gscQueryMetrics()->value('clicks'));

        $this->page()
            ->callAction('editGa4Countries', data: ['rows' => [
                ['country' => 'Spain', 'active_users' => 1],
                ['country' => 'Spain', 'active_users' => 2],
            ]])
            ->assertHasFormErrors();

        $this->assertSame(2, $this->october->ga4CountryMetrics()->count());
    }

    public function test_project_without_cycles_renders_safely(): void
    {
        $bare = Project::factory()->create();

        $this->get(ProjectResource::getUrl('analytics', ['record' => $bare]))
            ->assertOk()
            ->assertSee('no monthly cycles yet');

        Livewire::test(ProjectAnalytics::class, ['record' => $bare->getRouteKey()])
            ->assertSet('selectedCycle', '')
            ->assertActionHidden('editGscSummary');
    }
}
