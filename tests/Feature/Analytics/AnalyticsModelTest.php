<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SaveAuthorityMetricsAction;
use App\Actions\Analytics\SaveGa4CountryMetricsAction;
use App\Actions\Analytics\SaveGa4MonthlyMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\DataSource;
use App\Exceptions\InactiveUserAssignmentException;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\AuthorityMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\GscPageMetric;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class AnalyticsModelTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create(['name' => 'Casa Botanica']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    protected function gsc(array $attributes = [], ?User $user = null): GscMonthlyMetric
    {
        return app(SaveGscMonthlyMetricsAction::class)->handle($this->september, $attributes + [
            'clicks' => 1200, 'impressions' => 48000, 'ctr' => 2.5, 'average_position' => 14.3,
        ], $user ?? $this->manager);
    }

    protected function ga4(array $attributes = [], ?User $user = null): Ga4MonthlyMetric
    {
        return app(SaveGa4MonthlyMetricsAction::class)->handle($this->september, $attributes + [
            'active_users' => 5000, 'new_users' => 3200, 'sessions' => 7400, 'organic_sessions' => 4100,
            'engaged_sessions' => 4600, 'engagement_rate' => 62.16, 'average_engagement_time_seconds' => 95,
            'event_count' => 30000, 'key_events' => 120,
        ], $user ?? $this->manager);
    }

    protected function authority(array $attributes = [], ?User $user = null): AuthorityMetric
    {
        return app(SaveAuthorityMetricsAction::class)->handle($this->september, $attributes + [
            'moz_domain_authority' => 42, 'moz_linking_root_domains' => 310, 'ahrefs_domain_rating' => 48.5,
            'ahrefs_url_rating' => 31.0, 'backlinks_count' => 12500, 'referring_domains_count' => 640, 'notes' => 'Checked 15 Sep',
        ], $user ?? $this->manager);
    }

    public function test_summaries_are_one_per_cycle_and_related(): void
    {
        $gsc = $this->gsc();
        $ga4 = $this->ga4();
        $authority = $this->authority();

        $this->assertTrue($this->september->gscMonthlyMetric->is($gsc));
        $this->assertTrue($this->september->ga4MonthlyMetric->is($ga4));
        $this->assertTrue($this->september->authorityMetric->is($authority));
        $this->assertTrue($gsc->monthlyCycle->is($this->september));
        $this->assertTrue($gsc->enteredBy->is($this->manager));
        $this->assertTrue($ga4->enteredBy->is($this->manager));
        $this->assertTrue($authority->enteredBy->is($this->manager));

        // The database itself refuses a second summary per cycle.
        foreach ([
            fn () => GscMonthlyMetric::factory()->forCycle($this->september)->create(),
            fn () => Ga4MonthlyMetric::factory()->forCycle($this->september)->create(),
            fn () => AuthorityMetric::factory()->forCycle($this->september)->create(),
        ] as $duplicate) {
            try {
                $duplicate();
                $this->fail('Expected the unique constraint to reject a second summary.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, GscMonthlyMetric::query()->count());
        $this->assertSame(1, Ga4MonthlyMetric::query()->count());
        $this->assertSame(1, AuthorityMetric::query()->count());
    }

    public function test_cycle_has_many_query_page_and_country_rows(): void
    {
        $page = Page::factory()->forProject($this->project)->create(['url' => 'https://site.example/villas']);

        app(SaveGscQueryMetricsAction::class)->handle($this->september, [
            ['query' => 'villa rentals', 'clicks' => 120, 'impressions' => 4000, 'ctr' => 3.0, 'average_position' => 6.2],
            ['query' => 'luxury villas', 'clicks' => 80, 'impressions' => 2600],
        ], $this->manager);

        app(SaveGscPageMetricsAction::class)->handle($this->september, [
            ['page_url' => 'https://site.example/villas', 'page_id' => $page->id, 'clicks' => 200, 'impressions' => 5000, 'ctr' => 4.0, 'average_position' => 5.1],
            ['page_url' => 'https://site.example/blog/summer', 'clicks' => 50, 'impressions' => 900],
        ], $this->manager);

        app(SaveGa4CountryMetricsAction::class)->handle($this->september, [
            ['country' => 'United Kingdom', 'active_users' => 3000, 'new_users' => 2000, 'sessions' => 4500, 'engaged_sessions' => 2800, 'engagement_rate' => 62.2, 'event_count' => 20000, 'key_events' => 90],
            ['country' => 'Spain', 'active_users' => 900],
        ], $this->manager);

        $this->assertSame(2, $this->september->gscQueryMetrics()->count());
        $this->assertSame(2, $this->september->gscPageMetrics()->count());
        $this->assertSame(2, $this->september->ga4CountryMetrics()->count());

        $mapped = GscPageMetric::query()->where('page_url', 'https://site.example/villas')->firstOrFail();
        $unmapped = GscPageMetric::query()->where('page_url', 'https://site.example/blog/summer')->firstOrFail();

        $this->assertTrue($mapped->page->is($page));
        $this->assertTrue($mapped->monthlyCycle->is($this->september));
        $this->assertNull($unmapped->page_id);
        $this->assertSame('https://site.example/blog/summer', $unmapped->page_url);
        $this->assertNull($this->september->ga4CountryMetrics()->where('country', 'Spain')->value('sessions'));
    }

    public function test_data_source_supports_the_documented_values_and_only_manual_is_implemented(): void
    {
        $this->assertSame(
            ['manual', 'csv_import', 'gsc_api', 'ga4_api', 'ahrefs_api', 'semrush_api', 'dataforseo'],
            array_map(fn (DataSource $s): string => $s->value, DataSource::cases()),
        );
        $this->assertSame([DataSource::Manual], DataSource::implemented());
        $this->assertSame('Manual Entry', DataSource::Manual->getLabel());

        $gsc = $this->gsc();
        $this->assertSame(DataSource::Manual, $gsc->source);
        $this->assertNull($gsc->synced_at);
        $this->assertSame(DataSource::Manual, $this->gsc(['source' => 'manual'])->source);
        $this->assertSame(DataSource::Manual, $this->ga4()->source);
        $this->assertSame(DataSource::Manual, $this->authority()->source);

        foreach (['gsc_api', 'csv_import', 'ahrefs_api', 'nope'] as $source) {
            try {
                $this->gsc(['source' => $source]);
                $this->fail("Expected source [{$source}] to be rejected for manual entry.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_counts_reject_negative_and_fractional_values(): void
    {
        foreach ([
            fn () => $this->gsc(['clicks' => -1]),
            fn () => $this->gsc(['impressions' => 'ten']),
            fn () => $this->gsc(['clicks' => 1.5]),
            fn () => $this->gsc(['clicks' => null]),
            fn () => $this->ga4(['sessions' => -5]),
            fn () => $this->ga4(['average_engagement_time_seconds' => -1]),
            fn () => $this->authority(['backlinks_count' => -3]),
            fn () => $this->authority(['moz_linking_root_domains' => -1]),
            fn () => app(SaveGscQueryMetricsAction::class)->handle($this->september, [['query' => 'x', 'clicks' => -1, 'impressions' => 1]], $this->manager),
            fn () => app(SaveGa4CountryMetricsAction::class)->handle($this->september, [['country' => 'Spain', 'active_users' => -1]], $this->manager),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a negative or non-integer count to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, GscMonthlyMetric::query()->count());
        $this->assertSame(0, Ga4MonthlyMetric::query()->count());
        $this->assertSame(0, AuthorityMetric::query()->count());
        $this->assertSame(0, $this->september->gscQueryMetrics()->count());
        $this->assertSame(0, $this->september->ga4CountryMetrics()->count());

        // Zero is a legitimate count everywhere.
        $this->assertSame(0, $this->gsc(['clicks' => 0, 'impressions' => 0])->clicks);
        $this->assertSame(0, $this->ga4(['sessions' => 0, 'average_engagement_time_seconds' => 0])->sessions);
    }

    public function test_percentages_use_the_same_human_convention_for_gsc_and_ga4(): void
    {
        // 8.5 means 8.5% — stored as-is with two decimals, capped at 100.
        $gsc = $this->gsc(['ctr' => '8.5']);
        $this->assertSame('8.50', $gsc->ctr);
        $this->assertSame('8.50', $gsc->fresh()->ctr);

        $ga4 = $this->ga4(['engagement_rate' => 62.157]);
        $this->assertSame('62.16', $ga4->fresh()->engagement_rate);

        $rows = app(SaveGscQueryMetricsAction::class)->handle($this->september, [['query' => 'x', 'clicks' => 1, 'impressions' => 10, 'ctr' => 10]], $this->manager);
        $this->assertSame('10.00', $rows->first()->ctr);

        $countries = app(SaveGa4CountryMetricsAction::class)->handle($this->september, [['country' => 'Spain', 'engagement_rate' => 100]], $this->manager);
        $this->assertSame('100.00', $countries->first()->engagement_rate);

        // Fractions like 0.085 are NOT reinterpreted; they mean 0.085%.
        $this->assertSame('0.09', $this->gsc(['ctr' => 0.085])->fresh()->ctr);

        // Optional: no value means unknown.
        $this->assertNull($this->gsc(['ctr' => null])->ctr);
        $this->assertNull($this->ga4(['engagement_rate' => ''])->engagement_rate);

        foreach ([
            fn () => $this->gsc(['ctr' => 101]),
            fn () => $this->gsc(['ctr' => -0.1]),
            fn () => $this->gsc(['ctr' => 'high']),
            fn () => $this->ga4(['engagement_rate' => 150]),
            fn () => app(SaveGa4CountryMetricsAction::class)->handle($this->september, [['country' => 'Spain', 'engagement_rate' => -1]], $this->manager),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected an out-of-range percentage to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('between 0 and 100', $exception->getMessage());
            }
        }
    }

    public function test_average_position_is_positive_or_null_never_zero(): void
    {
        $this->assertSame('14.30', $this->gsc()->fresh()->average_position);
        $this->assertNull($this->gsc(['average_position' => null])->average_position);
        $this->assertNull($this->gsc(['average_position' => ''])->average_position);
        $this->assertSame('1.00', $this->gsc(['average_position' => 1])->fresh()->average_position);

        foreach ([0, '0', -3, 'top'] as $invalid) {
            foreach ([
                fn () => $this->gsc(['average_position' => $invalid]),
                fn () => app(SaveGscQueryMetricsAction::class)->handle($this->september, [['query' => 'x', 'clicks' => 1, 'impressions' => 1, 'average_position' => $invalid]], $this->manager),
                fn () => app(SaveGscPageMetricsAction::class)->handle($this->september, [['page_url' => 'https://site.example/x', 'clicks' => 1, 'impressions' => 1, 'average_position' => $invalid]], $this->manager),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail("Expected average position [{$invalid}] to be rejected.");
                } catch (InvalidArgumentException $exception) {
                    $this->assertStringContainsString('positive number', $exception->getMessage());
                }
            }
        }
    }

    public function test_authority_scores_are_validated_within_sensible_ranges(): void
    {
        $metric = $this->authority();
        $this->assertSame(42, $metric->moz_domain_authority);
        $this->assertSame('48.5', $metric->fresh()->ahrefs_domain_rating);
        $this->assertSame('31.0', $metric->fresh()->ahrefs_url_rating);
        $this->assertSame(12500, $metric->backlinks_count);
        $this->assertSame('Checked 15 Sep', $metric->notes);

        $edge = $this->authority(['moz_domain_authority' => 0, 'ahrefs_domain_rating' => 100, 'ahrefs_url_rating' => 0]);
        $this->assertSame(0, $edge->moz_domain_authority);
        $this->assertSame('100.0', $edge->fresh()->ahrefs_domain_rating);

        $blank = $this->authority(['moz_domain_authority' => null, 'ahrefs_domain_rating' => '', 'notes' => '  ']);
        $this->assertNull($blank->moz_domain_authority);
        $this->assertNull($blank->ahrefs_domain_rating);
        $this->assertNull($blank->notes);

        foreach ([
            ['moz_domain_authority' => 101],
            ['moz_domain_authority' => -1],
            ['moz_domain_authority' => 42.5],
            ['ahrefs_domain_rating' => 100.1],
            ['ahrefs_domain_rating' => -0.5],
            ['ahrefs_url_rating' => 'high'],
            ['notes' => str_repeat('n', 5001)],
        ] as $attributes) {
            try {
                $this->authority($attributes);
                $this->fail('Expected rejection for '.json_encode($attributes));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_gsc_page_mapping_must_belong_to_the_cycles_project(): void
    {
        $foreign = Page::factory()->create();
        $own = Page::factory()->forProject($this->project)->create();

        try {
            app(SaveGscPageMetricsAction::class)->handle($this->september, [
                ['page_url' => 'https://site.example/a', 'page_id' => $foreign->id, 'clicks' => 1, 'impressions' => 1],
            ], $this->manager);
            $this->fail('Expected a page from another project to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not belong to project "Casa Botanica"', $exception->getMessage());
        }

        $this->assertSame(0, GscPageMetric::query()->count());

        // Without a mapping the URL is stored as-is; with a same-project mapping both are kept.
        $rows = app(SaveGscPageMetricsAction::class)->handle($this->september, [
            ['page_url' => 'https://elsewhere.example/landing', 'clicks' => 3, 'impressions' => 30],
            ['page_url' => 'https://site.example/mapped', 'page_id' => $own->id, 'clicks' => 4, 'impressions' => 40],
        ], $this->manager);

        $unmapped = $rows->firstWhere('page_url', 'https://elsewhere.example/landing');
        $mapped = $rows->firstWhere('page_url', 'https://site.example/mapped');

        $this->assertNull($unmapped->page_id);
        $this->assertSame($own->id, $mapped->page_id);
        $this->assertSame('https://site.example/mapped', $mapped->page_url);

        // Removing the master page leaves the analytics URL intact.
        $own->delete();
        $this->assertSame('https://site.example/mapped', $mapped->fresh()->page_url);
        $this->assertTrue($mapped->fresh()->page->is($own));

        foreach (['', 'not a url', 'ftp://x.example/a', 'https://'.str_repeat('a', 500).'.example'] as $url) {
            try {
                app(SaveGscPageMetricsAction::class)->handle($this->september, [['page_url' => $url, 'clicks' => 1, 'impressions' => 1]], $this->manager);
                $this->fail("Expected URL [{$url}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_entered_by_must_be_an_active_user_with_project_access(): void
    {
        $member = User::factory()->seoExecutive()->create();
        $this->project->teamMembers()->attach($member);
        $inactive = User::factory()->seoManager()->inactive()->create();
        $outsider = User::factory()->seoExecutive()->create();

        $this->assertSame($member->id, $this->gsc([], $member)->entered_by);
        $this->assertSame($member->id, $this->ga4([], $member)->entered_by);
        $this->assertSame($member->id, $this->authority([], $member)->entered_by);
        $this->assertSame($this->manager->id, $this->gsc([], $this->manager)->fresh()->entered_by);
        $this->assertSame(User::factory()->superAdmin()->create()->id, $this->gsc([], User::query()->latest('id')->first())->fresh()->entered_by);

        $attempts = fn (User $user): array => [
            fn () => $this->gsc(['clicks' => 1], $user),
            fn () => $this->ga4(['sessions' => 1], $user),
            fn () => $this->authority(['moz_domain_authority' => 1], $user),
            fn () => app(SaveGscQueryMetricsAction::class)->handle($this->september, [['query' => 'x', 'clicks' => 1, 'impressions' => 1]], $user),
            fn () => app(SaveGscPageMetricsAction::class)->handle($this->september, [['page_url' => 'https://site.example/x', 'clicks' => 1, 'impressions' => 1]], $user),
            fn () => app(SaveGa4CountryMetricsAction::class)->handle($this->september, [['country' => 'Spain']], $user),
        ];

        foreach ($attempts($inactive) as $attempt) {
            try {
                $attempt();
                $this->fail('Expected InactiveUserAssignmentException.');
            } catch (InactiveUserAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach ($attempts($outsider) as $attempt) {
            try {
                $attempt();
                $this->fail('Expected UnauthorizedProjectUserException.');
            } catch (UnauthorizedProjectUserException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1200, $this->september->gscMonthlyMetric->fresh()->clicks);
        $this->assertSame(0, $this->september->gscQueryMetrics()->count());
        $this->assertSame(0, $this->september->gscPageMetrics()->count());
        $this->assertSame(0, $this->september->ga4CountryMetrics()->count());
    }
}
