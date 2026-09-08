<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SaveAuthorityMetricsAction;
use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\Backlinks\SetBacklinkStatusAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Enums\BacklinkStatus;
use App\Enums\ContentType;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Analytics are reporting inputs. They never move the operational modules
 * (targets, keywords, pages, rankings) and nothing from later milestones
 * sneaks in.
 */
class AnalyticsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected TargetProgressService $progress;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 10],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4],
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 6],
        ])->create();
        $this->project = Project::factory()->withPackage($package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->progress = app(TargetProgressService::class);
    }

    public function test_gsc_rows_never_modify_tracked_keywords_or_page_master_data(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create(['keyword' => 'villa rentals']);
        $page = Page::factory()->forProject($this->project)->create(['url' => 'https://site.example/villas', 'title' => 'Villas']);
        $keywordsBefore = Keyword::query()->count();
        $pagesBefore = Page::query()->count();
        $keywordAttributes = $keyword->fresh()->getAttributes();

        app(SaveGscQueryMetricsAction::class)->handle($this->september, [
            ['query' => 'villa rentals', 'clicks' => 10, 'impressions' => 100],
            ['query' => 'brand new query nobody tracks', 'clicks' => 1, 'impressions' => 10],
        ], $this->manager);

        app(SaveGscPageMetricsAction::class)->handle($this->september, [
            ['page_url' => 'https://site.example/villas', 'page_id' => $page->id, 'clicks' => 5, 'impressions' => 50],
            ['page_url' => 'https://site.example/not-in-master-data', 'clicks' => 2, 'impressions' => 20],
        ], $this->manager);

        $this->assertSame($keywordsBefore, Keyword::query()->count());
        $this->assertSame($pagesBefore, Page::query()->count());
        $this->assertSame($keywordAttributes, $keyword->fresh()->getAttributes());
        $this->assertSame('Villas', $page->fresh()->title);
        $this->assertSame('https://site.example/villas', $page->fresh()->url);
        $this->assertSame(0, $keyword->rankingSnapshots()->count());
        $this->assertSame(0, $page->optimizations()->count());
        $this->assertSame(0, $this->progress->pagesOptimisedActual($this->september));
    }

    public function test_authority_backlinks_and_operational_backlinks_are_independent(): void
    {
        $link = app(CreateBacklinkAction::class)->handle($this->project, [
            'monthly_cycle_id' => $this->september->id,
            'published_url' => 'https://partner.example/post',
            'type' => 'guest_post',
            'status' => 'live',
        ], $this->manager);

        $this->assertSame('1 / 10', $this->progress->backlinks($this->september)->format());

        // A vendor total of 12,500 backlinks changes nothing operationally.
        $authority = app(SaveAuthorityMetricsAction::class)->handle($this->september, ['backlinks_count' => 12500, 'referring_domains_count' => 640], $this->manager);

        $this->assertSame('1 / 10', $this->progress->backlinks($this->september)->format());
        $this->assertSame(1, $this->september->backlinks()->count());

        // Operational changes never touch the authority snapshot.
        app(SetBacklinkStatusAction::class)->handle($link, BacklinkStatus::Removed);
        app(CreateBacklinkAction::class)->handle($this->project, [
            'monthly_cycle_id' => $this->september->id,
            'published_url' => 'https://another.example/post',
            'type' => 'citation',
            'status' => 'live',
        ], $this->manager);

        $this->assertSame('1 / 10', $this->progress->backlinks($this->september)->format());
        $this->assertSame(12500, $authority->fresh()->backlinks_count);
        $this->assertSame(640, $authority->fresh()->referring_domains_count);
        $this->assertSame($authority->updated_at->toDateTimeString(), $authority->fresh()->updated_at->toDateTimeString());
    }

    public function test_analytics_do_not_alter_blogs_pages_optimised_or_rankings(): void
    {
        ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        $page = Page::factory()->forProject($this->project)->create();
        app(RecordPageOptimizationAction::class)->handle($this->project, ['page_id' => $page->id, 'monthly_cycle_id' => $this->september->id, 'content_updated' => true], $this->manager);
        $keyword = Keyword::factory()->forProject($this->project)->create();
        RankingSnapshot::factory()->forKeyword($keyword)->forCycle($this->september)->at('2026-09-10 09:00', 4)->create();

        $this->assertSame('1 / 4', $this->progress->blogs($this->september)->format());
        $this->assertSame('1 / 6', $this->progress->pagesOptimised($this->september)->format());

        app(SaveGscQueryMetricsAction::class)->handle($this->september, [['query' => $keyword->keyword, 'clicks' => 500, 'impressions' => 5000, 'average_position' => 1.2]], $this->manager);
        app(SaveGscPageMetricsAction::class)->handle($this->september, [['page_url' => $page->url, 'page_id' => $page->id, 'clicks' => 500, 'impressions' => 5000]], $this->manager);
        app(SaveAuthorityMetricsAction::class)->handle($this->september, ['moz_domain_authority' => 60, 'backlinks_count' => 99999], $this->manager);

        $this->assertSame('1 / 4', $this->progress->blogs($this->september)->format());
        $this->assertSame('1 / 6', $this->progress->pagesOptimised($this->september)->format());
        $this->assertSame('0 / 10', $this->progress->backlinks($this->september)->format());
        $this->assertSame(1, RankingSnapshot::query()->count());
        $this->assertSame(4, RankingSnapshot::query()->value('position'));
        $this->assertSame(1, $this->september->pageOptimizations()->count());
    }

    public function test_no_pdf_or_integrations_are_introduced_by_analytics(): void
    {
        foreach (['report_snapshots', 'csv_imports', 'analytics_syncs', 'oauth_tokens'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Table [{$table}] must not exist yet.");
        }

        foreach ([
            app_path('Services/Pdf'),
            app_path('Services/Integrations'),
            app_path('Jobs/SyncGscMetrics.php'),
            app_path('Jobs/SyncGa4Metrics.php'),
            app_path('Console/Commands/SyncAnalytics.php'),
        ] as $path) {
            $this->assertFalse(File::exists($path), "[{$path}] belongs to a later milestone.");
        }

        $this->assertEmpty(array_filter(
            array_keys(app('router')->getRoutes()->getRoutesByName()),
            fn (string $name): bool => str_contains($name, 'oauth') || str_contains($name, 'google') || str_contains($name, 'csv'),
        ));

        $this->assertFalse(class_exists('App\Actions\Analytics\SyncGscMetricsAction'));
        $this->assertFalse(class_exists('App\Actions\Analytics\ImportAnalyticsCsvAction'));
    }
}
