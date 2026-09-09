<?php

namespace Tests\Feature\Reports;

use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Projects\SyncProjectTargetOverridesAction;
use App\Actions\Reports\UpdateReportSectionTextAction;
use App\Models\Keyword;
use App\Models\User;
use App\Services\Reports\ReportSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsCompleteReports;
use Tests\TestCase;

class ReportSnapshotBuilderTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');

        $this->buildCompleteReport();
    }

    /**
     * @return array<string, mixed>
     */
    protected function build(): array
    {
        return app(ReportSnapshotBuilder::class)->build($this->report->fresh());
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    protected function section(array $snapshot, string $key): array
    {
        return collect($snapshot['sections'])->firstWhere('key', $key);
    }

    public function test_snapshot_carries_client_project_period_and_report_metadata(): void
    {
        $finalizer = User::factory()->superAdmin()->create(['name' => 'Ava Admin']);
        $snapshot = app(ReportSnapshotBuilder::class)->build($this->report, $finalizer, Carbon::parse('2026-10-02 09:00:00'));

        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame('Casa Botanica Ltd', $snapshot['client']['name']);
        $this->assertSame('Casa Botanica Holdings', $snapshot['client']['company_name']);
        $this->assertSame('Casa Botanica', $snapshot['project']['name']);
        $this->assertSame('https://casabotanica.example', $snapshot['project']['website_url']);
        $this->assertSame('Marbella, Spain', $snapshot['project']['target_location']);
        $this->assertSame('Growth', $snapshot['project']['package']['name']);
        $this->assertSame(['year' => 2026, 'month' => 9, 'label' => 'September 2026'], array_intersect_key($snapshot['period'], array_flip(['year', 'month', 'label'])));
        $this->assertSame('2026-09-01', $snapshot['period']['starts_on']);
        $this->assertSame('2026-09-30', $snapshot['period']['ends_on']);
        // Built for finalization, the snapshot describes the final report; a plain build keeps the live status.
        $this->assertSame('final', $snapshot['report']['status']);
        $this->assertSame('draft', $this->build()['report']['status']);
        $this->assertSame('A strong month with steady organic growth.', $snapshot['report']['executive_summary']);
        $this->assertTrue($snapshot['report']['readiness']['ready']);
        $this->assertSame('Ava Admin', $snapshot['finalized_by']['name']);
        $this->assertSame('2026-10-02T09:00:00+00:00', $snapshot['finalized_at']);
        $this->assertArrayNotHasKey('review_notes', $snapshot['report']);
    }

    public function test_targets_come_from_the_cycle_snapshot_not_todays_package_or_overrides(): void
    {
        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 5],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 1],
        ]);
        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['backlinks' => 99]);

        $snapshot = $this->build();
        $targets = collect($snapshot['targets'])->keyBy('key');

        $this->assertSame(50, $targets['backlinks']['target']);
        $this->assertSame(1, $targets['backlinks']['actual']);
        $this->assertSame('1 / 50', $targets['backlinks']['display']);
        $this->assertSame(5, $targets['guest_posts']['target']);
        $this->assertSame(8, $targets['blogs']['target']);
        $this->assertSame(6, $targets['pages_optimized']['target']);
        $this->assertSame(['backlinks', 'guest_posts', 'blogs', 'pages_optimized'], $targets->keys()->all());

        $backlinks = $this->section($snapshot, 'backlinks');
        $this->assertSame('1 / 50', $backlinks['data']['progress']['backlinks']['display']);
        $this->assertSame('1 / 5', $backlinks['data']['progress']['guest_posts']['display']);
    }

    public function test_sections_follow_the_report_snapshot_configuration_and_include_narrative(): void
    {
        $this->report->sections()->where('section_key', 'backlinks')->update(['title' => 'Link Building', 'sort_order' => 1, 'is_required' => false]);
        $this->report->sections()->where('section_key', 'audience_country')->update(['is_enabled' => false]);
        app(UpdateReportSectionTextAction::class)->handle($this->report->sections()->where('section_key', 'rankings')->firstOrFail(), 'Rankings commentary.');

        $snapshot = $this->build();

        $this->assertSame('backlinks', $snapshot['sections'][0]['key']);
        $this->assertSame('Link Building', $snapshot['sections'][0]['title']);
        $this->assertFalse($snapshot['sections'][0]['required']);
        $this->assertSame(1, $snapshot['sections'][0]['sort_order']);

        $audience = $this->section($snapshot, 'audience_country');
        $this->assertFalse($audience['enabled']);
        $this->assertSame([], $audience['data']);

        $this->assertSame('Rankings commentary.', $this->section($snapshot, 'rankings')['custom_text']);
        $this->assertSame('A strong month with steady organic growth.', $this->section($snapshot, 'executive_summary')['data']['summary']);
        $this->assertCount(10, $snapshot['sections']);
    }

    public function test_snapshot_contains_notes_and_every_metric_family(): void
    {
        $snapshot = $this->build();

        $this->assertSame('Ranked #1 for villa rentals marbella.', $snapshot['notes']['win'][0]['body']);
        $this->assertSame('Top spot', $snapshot['notes']['win'][0]['title']);
        $this->assertSame('Site migration slipped a week.', $snapshot['notes']['challenge'][0]['body']);
        $this->assertSame('Add FAQ schema to villa pages.', $this->section($snapshot, 'recommendations')['data']['recommendations'][0]['body']);
        $this->assertSame('Publish the pricing guide.', $this->section($snapshot, 'recommendations')['data']['next_month_focus'][0]['body']);
        $this->assertCount(1, $this->section($snapshot, 'executive_summary')['data']['wins']);
        $this->assertCount(1, $this->section($snapshot, 'executive_summary')['data']['challenges']);

        $authority = $this->section($snapshot, 'site_authority')['data'];
        $this->assertTrue($authority['available']);
        $this->assertSame(42, $authority['moz_domain_authority']);
        $this->assertSame(48.5, $authority['ahrefs_domain_rating']);
        $this->assertSame(12500, $authority['backlinks_count']);

        $gsc = $this->section($snapshot, 'organic_search')['data'];
        $this->assertSame(1200, $gsc['clicks']);
        $this->assertSame(48000, $gsc['impressions']);
        $this->assertSame(2.5, $gsc['ctr']);
        $this->assertSame(14.3, $gsc['average_position']);

        $queries = $this->section($snapshot, 'top_keywords')['data']['queries'];
        $this->assertSame(['villa rentals marbella', 'luxury villas'], array_column($queries, 'query'));
        $this->assertSame(120, $queries[0]['clicks']);

        $pages = $this->section($snapshot, 'landing_pages')['data']['pages'];
        $this->assertSame('https://casabotanica.example/villas', $pages[0]['page_url']);
        $this->assertSame(200, $pages[0]['clicks']);
        $this->assertNull($pages[1]['ctr']);

        $ga4 = $this->section($snapshot, 'website_traffic')['data'];
        $this->assertSame(5000, $ga4['active_users']);
        $this->assertSame(62.16, $ga4['engagement_rate']);
        $this->assertSame(95, $ga4['average_engagement_time_seconds']);

        $countries = $this->section($snapshot, 'audience_country')['data']['countries'];
        $this->assertSame(['United Kingdom', 'Spain'], array_column($countries, 'country'));
        $this->assertSame(3000, $countries[0]['active_users']);

        $rankings = $this->section($snapshot, 'rankings')['data'];
        $this->assertSame(1, $rankings['tracked_count']);
        $this->assertSame('villa rentals marbella', $rankings['keywords'][0]['keyword']);
        $this->assertSame(24, $rankings['keywords'][0]['month_start_position']);
        $this->assertSame(8, $rankings['keywords'][0]['latest_position']);
        $this->assertSame('improved', $rankings['keywords'][0]['movement']['direction']);
        $this->assertSame(16, $rankings['keywords'][0]['movement']['change']);
        $this->assertSame('Improved 16 positions', $rankings['keywords'][0]['movement']['label']);
        $this->assertSame(1, $rankings['summary']['improved']);

        $backlinks = $this->section($snapshot, 'backlinks')['data'];
        $this->assertSame(1, $backlinks['totals']['recorded']);
        $this->assertSame(1, $backlinks['totals']['live']);
        $this->assertSame('https://partner.example/guest-post', $backlinks['links'][0]['published_url']);
        $this->assertSame('guest_post', $backlinks['links'][0]['type']);
        $this->assertSame('2026-09-10', $backlinks['links'][0]['published_date']);
        $this->assertSame(1, collect($backlinks['live_type_breakdown'])->firstWhere('type', 'guest_post')['count']);
    }

    public function test_snapshot_is_deterministic_and_plain_serializable(): void
    {
        // Insert rows in scrambled order; output order must not depend on insertion.
        $this->cycle->gscQueryMetrics()->create(['query' => 'aaa first alphabetically', 'clicks' => 120, 'impressions' => 4000]);
        $this->cycle->ga4CountryMetrics()->create(['country' => 'Andorra', 'active_users' => 3000]);
        Keyword::factory()->forProject($this->project)->create(['keyword' => 'aardvark hotels']);
        $this->cycle->monthlyNotes()->create(['type' => 'win', 'body' => 'Earlier win', 'sort_order' => 0, 'created_by' => $this->manager->id]);

        $first = $this->build();
        $second = $this->build();

        unset($first['generated_at'], $second['generated_at']);
        $this->assertSame($first, $second);

        $this->assertSame(['aaa first alphabetically', 'villa rentals marbella', 'luxury villas'], array_column($this->section($first, 'top_keywords')['data']['queries'], 'query'));
        // Tie on active users: sessions decide, then name.
        $this->assertSame(['United Kingdom', 'Andorra', 'Spain'], array_column($this->section($first, 'audience_country')['data']['countries'], 'country'));
        $this->assertSame(['aardvark hotels', 'villa rentals marbella'], array_column($this->section($first, 'rankings')['data']['keywords'], 'keyword'));
        $this->assertSame(['Earlier win', 'Ranked #1 for villa rentals marbella.'], array_column($first['notes']['win'], 'body'));
        $this->assertSame(['executive_summary', 'site_authority', 'organic_search', 'website_traffic', 'top_keywords', 'landing_pages', 'rankings', 'audience_country', 'backlinks', 'recommendations'], array_column($first['sections'], 'key'));

        // Plain arrays and scalars only: JSON round-trips (31.0 becomes 31, nothing else changes).
        $json = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertEquals($first, json_decode($json, true));
        $this->assertStringNotContainsString('App\\\\Models', $json);

        array_walk_recursive($first, function ($value): void {
            $this->assertTrue($value === null || is_scalar($value), 'Snapshot leaf must be a scalar or null, got '.get_debug_type($value));
        });
    }
}
