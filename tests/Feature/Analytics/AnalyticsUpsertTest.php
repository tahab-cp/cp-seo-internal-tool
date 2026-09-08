<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SaveAuthorityMetricsAction;
use App\Actions\Analytics\SaveGa4CountryMetricsAction;
use App\Actions\Analytics\SaveGa4MonthlyMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Models\AuthorityMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class AnalyticsUpsertTest extends TestCase
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
    }

    public function test_saving_each_summary_twice_updates_the_existing_row(): void
    {
        $other = User::factory()->superAdmin()->create();

        $gsc1 = app(SaveGscMonthlyMetricsAction::class)->handle($this->september, ['clicks' => 100, 'impressions' => 1000, 'ctr' => 10, 'average_position' => 12], $this->manager);
        $gsc2 = app(SaveGscMonthlyMetricsAction::class)->handle($this->september, ['clicks' => 150, 'impressions' => 1200], $other);

        $this->assertSame($gsc1->id, $gsc2->id);
        $this->assertSame(1, GscMonthlyMetric::query()->count());
        $this->assertSame(150, $gsc2->fresh()->clicks);
        $this->assertNull($gsc2->fresh()->ctr);
        $this->assertSame($other->id, $gsc2->fresh()->entered_by);

        $ga1 = app(SaveGa4MonthlyMetricsAction::class)->handle($this->september, ['sessions' => 500, 'engagement_rate' => 55], $this->manager);
        $ga2 = app(SaveGa4MonthlyMetricsAction::class)->handle($this->september, ['sessions' => 650, 'engagement_rate' => 58.5, 'key_events' => 12], $this->manager);

        $this->assertSame($ga1->id, $ga2->id);
        $this->assertSame(1, Ga4MonthlyMetric::query()->count());
        $this->assertSame(650, $ga2->fresh()->sessions);
        $this->assertSame('58.50', $ga2->fresh()->engagement_rate);
        $this->assertSame(12, $ga2->fresh()->key_events);

        $au1 = app(SaveAuthorityMetricsAction::class)->handle($this->september, ['moz_domain_authority' => 40, 'backlinks_count' => 1000], $this->manager);
        $au2 = app(SaveAuthorityMetricsAction::class)->handle($this->september, ['moz_domain_authority' => 41, 'backlinks_count' => 1100, 'notes' => 'Grew'], $this->manager);

        $this->assertSame($au1->id, $au2->id);
        $this->assertSame(1, AuthorityMetric::query()->count());
        $this->assertSame(41, $au2->fresh()->moz_domain_authority);
        $this->assertSame(1100, $au2->fresh()->backlinks_count);
        $this->assertSame('Grew', $au2->fresh()->notes);
    }

    public function test_gsc_query_identity_is_the_normalised_query_per_cycle(): void
    {
        $first = app(SaveGscQueryMetricsAction::class)->handle($this->september, [
            ['query' => 'Villa Rentals', 'clicks' => 10, 'impressions' => 100],
            ['query' => 'luxury villas', 'clicks' => 5, 'impressions' => 50],
        ], $this->manager);

        $villaId = $first->firstWhere('query', 'villa rentals')->id;
        $this->assertSame('villa rentals', $first->firstWhere('query', 'villa rentals')->query);

        // Same identity (case / whitespace) updates in place; omitted rows are removed; new rows added.
        $second = app(SaveGscQueryMetricsAction::class)->handle($this->september, [
            ['query' => '  villa   RENTALS ', 'clicks' => 20, 'impressions' => 200, 'ctr' => 10],
            ['query' => 'beach houses', 'clicks' => 3, 'impressions' => 30],
        ], $this->manager);

        $this->assertSame(2, $this->september->gscQueryMetrics()->count());
        $this->assertSame($villaId, $second->firstWhere('query', 'villa rentals')->id);
        $this->assertSame(20, $second->firstWhere('query', 'villa rentals')->clicks);
        $this->assertNull($this->september->gscQueryMetrics()->where('query', 'luxury villas')->first());
        $this->assertNotNull($this->september->gscQueryMetrics()->where('query', 'beach houses')->first());

        // The same query twice in one submission is rejected, atomically.
        try {
            app(SaveGscQueryMetricsAction::class)->handle($this->september, [
                ['query' => 'Villa rentals', 'clicks' => 1, 'impressions' => 1],
                ['query' => 'villa rentals', 'clicks' => 2, 'impressions' => 2],
            ], $this->manager);
            $this->fail('Expected duplicate queries to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('villa rentals', $exception->getMessage());
        }

        $this->assertSame(20, $this->september->gscQueryMetrics()->where('query', 'villa rentals')->value('clicks'));

        // The same query in another month is a different row.
        app(SaveGscQueryMetricsAction::class)->handle($this->october, [['query' => 'villa rentals', 'clicks' => 99, 'impressions' => 999]], $this->manager);
        $this->assertSame(20, $this->september->gscQueryMetrics()->where('query', 'villa rentals')->value('clicks'));
        $this->assertSame(99, $this->october->gscQueryMetrics()->where('query', 'villa rentals')->value('clicks'));
    }

    public function test_gsc_page_identity_is_the_page_url_per_cycle(): void
    {
        $first = app(SaveGscPageMetricsAction::class)->handle($this->september, [
            ['page_url' => 'https://site.example/villas', 'clicks' => 10, 'impressions' => 100],
            ['page_url' => 'https://site.example/about', 'clicks' => 1, 'impressions' => 10],
        ], $this->manager);

        $villasId = $first->firstWhere('page_url', 'https://site.example/villas')->id;

        $second = app(SaveGscPageMetricsAction::class)->handle($this->september, [
            ['page_url' => ' https://site.example/villas ', 'clicks' => 30, 'impressions' => 300],
        ], $this->manager);

        $this->assertSame(1, $this->september->gscPageMetrics()->count());
        $this->assertSame($villasId, $second->first()->id);
        $this->assertSame(30, $second->first()->clicks);

        try {
            app(SaveGscPageMetricsAction::class)->handle($this->september, [
                ['page_url' => 'https://site.example/villas', 'clicks' => 1, 'impressions' => 1],
                ['page_url' => 'https://SITE.example/villas', 'clicks' => 2, 'impressions' => 2],
            ], $this->manager);
            $this->fail('Expected duplicate page URLs to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(30, $this->september->gscPageMetrics()->value('clicks'));

        app(SaveGscPageMetricsAction::class)->handle($this->october, [['page_url' => 'https://site.example/villas', 'clicks' => 7, 'impressions' => 70]], $this->manager);
        $this->assertSame(30, $this->september->gscPageMetrics()->value('clicks'));
        $this->assertSame(7, $this->october->gscPageMetrics()->value('clicks'));
    }

    public function test_ga4_country_identity_is_the_country_per_cycle(): void
    {
        $first = app(SaveGa4CountryMetricsAction::class)->handle($this->september, [
            ['country' => 'United Kingdom', 'active_users' => 100],
            ['country' => 'Spain', 'active_users' => 40],
        ], $this->manager);

        $ukId = $first->firstWhere('country', 'United Kingdom')->id;

        $second = app(SaveGa4CountryMetricsAction::class)->handle($this->september, [
            ['country' => 'united  kingdom', 'active_users' => 160, 'sessions' => 300],
            ['country' => 'France', 'active_users' => 20],
        ], $this->manager);

        $this->assertSame(2, $this->september->ga4CountryMetrics()->count());
        $this->assertSame($ukId, $second->firstWhere('active_users', 160)->id);
        $this->assertSame('united kingdom', $second->firstWhere('active_users', 160)->country);
        $this->assertNull($this->september->ga4CountryMetrics()->where('country', 'Spain')->first());

        try {
            app(SaveGa4CountryMetricsAction::class)->handle($this->september, [
                ['country' => 'Spain', 'active_users' => 1],
                ['country' => 'SPAIN', 'active_users' => 2],
            ], $this->manager);
            $this->fail('Expected duplicate countries to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(2, $this->september->ga4CountryMetrics()->count());

        app(SaveGa4CountryMetricsAction::class)->handle($this->october, [['country' => 'United Kingdom', 'active_users' => 5]], $this->manager);
        $this->assertSame(160, $this->september->ga4CountryMetrics()->where('country', 'united kingdom')->value('active_users'));
        $this->assertSame(5, $this->october->ga4CountryMetrics()->where('country', 'United Kingdom')->value('active_users'));
    }

    public function test_september_and_october_analytics_are_isolated(): void
    {
        app(SaveGscMonthlyMetricsAction::class)->handle($this->september, ['clicks' => 900, 'impressions' => 9000], $this->manager);
        app(SaveGa4MonthlyMetricsAction::class)->handle($this->september, ['sessions' => 9], $this->manager);
        app(SaveAuthorityMetricsAction::class)->handle($this->september, ['moz_domain_authority' => 9], $this->manager);

        app(SaveGscMonthlyMetricsAction::class)->handle($this->october, ['clicks' => 1000, 'impressions' => 10000], $this->manager);
        app(SaveGa4MonthlyMetricsAction::class)->handle($this->october, ['sessions' => 10], $this->manager);
        app(SaveAuthorityMetricsAction::class)->handle($this->october, ['moz_domain_authority' => 10], $this->manager);

        $this->assertSame(2, GscMonthlyMetric::query()->count());
        $this->assertSame(900, $this->september->fresh()->gscMonthlyMetric->clicks);
        $this->assertSame(1000, $this->october->fresh()->gscMonthlyMetric->clicks);
        $this->assertSame(9, $this->september->fresh()->ga4MonthlyMetric->sessions);
        $this->assertSame(10, $this->october->fresh()->ga4MonthlyMetric->sessions);
        $this->assertSame(9, $this->september->fresh()->authorityMetric->moz_domain_authority);
        $this->assertSame(10, $this->october->fresh()->authorityMetric->moz_domain_authority);

        // Another project's same-period cycle is untouched.
        $otherCycle = app(CreateMonthlyCycleAction::class)->handle(Project::factory()->create(), new CyclePeriod(2026, 9));
        $this->assertNull($otherCycle->gscMonthlyMetric);
    }
}
