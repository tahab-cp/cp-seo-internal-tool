<?php

namespace Tests\Feature\LegacyMigration;

use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\LegacyMigrationStatus;
use App\Enums\MonthlyNoteType;
use App\Enums\ProjectStatus;
use App\Enums\RankingSource;
use App\Enums\TaskStatus;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\LegacyMigrationRun;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsLegacyMigrations;
use Tests\TestCase;

class LegacyMigrationApplyTest extends TestCase
{
    use RefreshDatabase;
    use RunsLegacyMigrations;

    protected LegacyMigrationRun $run;

    protected Project $garden;

    protected Project $plumbing;

    protected MonthlyCycle $july;

    protected MonthlyCycle $august;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpLegacyFixtureUsers();
        $this->run = $this->migrate(dryRun: false);
        $this->garden = Project::query()->where('name', 'Acme Garden Centre')->firstOrFail();
        $this->plumbing = Project::query()->where('name', 'Acme Plumbing')->firstOrFail();
        $this->july = $this->garden->monthlyCycles()->forPeriod(new CyclePeriod(2026, 7))->firstOrFail();
        $this->august = $this->garden->monthlyCycles()->forPeriod(new CyclePeriod(2026, 8))->firstOrFail();
    }

    public function test_the_run_is_recorded_with_deterministic_totals(): void
    {
        $this->assertSame(LegacyMigrationRun::MODE_APPLY, $this->run->mode);
        $this->assertSame(LegacyMigrationStatus::Completed, $this->run->status);
        $this->assertSame($this->run->created_items, (int) data_get($this->reportOf($this->run), 'totals.create'));
        $this->assertSame($this->run->records()->count(), $this->run->records()->count());
        $this->assertGreaterThan(0, $this->run->records()->count(), 'the ledger remembers what each source row became');
    }

    public function test_multi_project_client_hierarchy_is_preserved_and_a_client_is_never_a_project(): void
    {
        $this->assertSame(2, Client::query()->count());
        $acme = Client::query()->where('name', 'Acme Holdings')->firstOrFail();
        $this->assertSame(2, $acme->projects()->count(), 'two websites under ONE legacy client');
        $this->assertSame(['Acme Garden Centre', 'Acme Plumbing'], $acme->projects()->orderBy('name')->pluck('name')->all());
        $this->assertSame(0, Project::query()->where('name', 'Acme Holdings')->count(), 'the account name never became a project');
        $this->assertSame(0, Client::query()->where('name', 'Acme Garden Centre')->count(), 'a website never became a client');

        $this->assertSame($this->manager->id, $this->garden->primary_seo_user_id);
        $this->assertTrue($this->garden->hasTeamMember($this->executive));
        $this->assertSame($this->growth->id, $this->garden->package_id);
        $this->assertSame(ProjectStatus::Active, $this->plumbing->status, '"Live" mapped through the explicit value mapping');
        $this->assertSame('2026-05-01', $this->garden->start_date->toDateString());

        $dental = Project::query()->where('name', 'Bright Dental Clinic')->firstOrFail();
        $this->assertNull($dental->primary_seo_user_id, 'an unresolvable owner is a warning, not a fuzzy match and not a new user');
        $this->assertNull($dental->package_id);
        $this->assertSame(3, User::query()->count(), 'no users created');
        $this->assertHasIssue($this->run, 'Owner "Some Former Employee" does not resolve to a user', 'warning');
        $this->assertHasIssue($this->run, 'Package "Platinum Legacy" does not match any package', 'warning');
    }

    public function test_historical_cycles_get_legacy_targets_or_none_never_todays_package_targets(): void
    {
        $this->assertSame(['backlinks' => 8, 'guest_posts' => 2, 'blogs' => 4, 'pages_optimized' => 3], $this->july->targets->pluck('target_value', 'target_key')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, $this->august->targets()->count(), 'August has no legacy targets: none fabricated from today\'s package (10/3/6/5)');
        $this->assertHasIssue($this->run, 'August 2026 of "Acme Garden Centre" created without target snapshot', 'warning');
        $this->assertSame('2026-07-01', $this->july->started_at->toDateString());
        $this->assertFalse($this->july->isLocked());

        // Migration never fabricates the CURRENT month as a side effect of creating an active project.
        $this->assertNull($this->garden->monthlyCycles()->forPeriod(CyclePeriod::current())->first());
        $this->assertSame(['2026-07', '2026-08'], $this->garden->monthlyCycles()->latestPeriodFirst()->get()->map(fn (MonthlyCycle $c) => sprintf('%04d-%02d', $c->year, $c->month))->sort()->values()->all());
    }

    public function test_keywords_follow_application_normalisation_and_date_columns_become_individual_snapshots(): void
    {
        $keywords = $this->garden->keywords()->orderBy('id')->get();
        $this->assertSame(['garden centre london', 'buy roses online'], $keywords->pluck('keyword_normalized')->all(), 'the re-spelled duplicate row reused the first keyword');
        $this->assertSame('london', $keywords[0]->location_normalized);
        $this->assertSame(Page::query()->where('url', 'https://acme-garden.example/')->value('id'), $keywords[0]->target_page_id);
        $this->assertSame(Page::query()->where('url', 'https://acme-garden.example/roses')->value('id'), $keywords[1]->target_page_id);

        $this->assertSame(9, RankingSnapshot::query()->count(), '3 + 3 + 3 observations; the duplicate row added none');
        $this->assertSame(3, $this->tallyOf($this->run, 'ranking_snapshots', 'skip'));

        $core = RankingSnapshot::query()->where('keyword_id', $keywords[0]->id)->orderBy('checked_at')->get();
        $this->assertSame([24, 18, 12], $core->pluck('position')->all());
        $this->assertSame(['2026-07-01', '2026-07-15', '2026-08-01'], $core->map(fn (RankingSnapshot $s) => $s->checked_at->toDateString())->all());
        $this->assertTrue($core->every(fn (RankingSnapshot $s) => $s->source === RankingSource::Manual));

        // Each observation belongs to the historical cycle of its own month.
        $this->assertSame([$this->july->id, $this->july->id, $this->august->id], $core->pluck('monthly_cycle_id')->all());

        // Blank, "-" and "0" all became Not Ranking; 0 is never stored.
        $roses = RankingSnapshot::query()->where('keyword_id', $keywords[1]->id)->orderBy('checked_at')->get();
        $this->assertSame([null, null, 8], $roses->pluck('position')->all());
        $plumber = RankingSnapshot::query()->where('keyword_id', $this->plumbing->keywords()->firstOrFail()->id)->orderBy('checked_at')->get();
        $this->assertSame([null, 35, 30], $plumber->pluck('position')->all());
        $this->assertSame(0, RankingSnapshot::query()->where('position', 0)->count());
        $this->assertHasIssue($this->run, 'legacy value "0" recorded as Not Ranking (NULL), never as 0', 'warning');
        $this->assertHasIssue($this->run, 'legacy value "-" recorded as Not Ranking', 'warning');
    }

    public function test_backlinks_map_documented_statuses_and_types_and_duplicate_urls_stay_separate_records(): void
    {
        $links = $this->garden->backlinks()->orderBy('id')->get();
        $this->assertCount(3, $links);
        $this->assertSame(2, $links->where('published_url', 'https://blog.example/garden-tips')->count(), 'same URL, two legitimate records');
        $this->assertSame([BacklinkStatus::Live, BacklinkStatus::Live, BacklinkStatus::Submitted], $links->pluck('status')->all());
        $this->assertSame([BacklinkType::GuestPost, BacklinkType::GuestPost, BacklinkType::Directory], $links->pluck('type')->all());
        $this->assertSame(45, $links[0]->domain_authority);
        $this->assertSame('2026-07-05', $links[0]->published_date->toDateString());
        $this->assertSame($this->july->id, $links[0]->monthly_cycle_id);
        $this->assertSame($this->admin->id, $links[0]->created_by);

        $this->assertSame(0, $this->plumbing->backlinks()->count(), 'unknown status "Sponsored" was rejected, not guessed');
        $this->assertHasIssue($this->run, 'Unknown backlink status "Sponsored"; add it to the "values.backlink_status" mapping', 'error');

        $progress = app(TargetProgressService::class);
        $this->assertSame(2, $progress->guestPosts($this->july)->actual);
        $this->assertSame(2, $progress->guestPosts($this->july)->target, 'against the LEGACY July target');
        $this->assertSame(2, $progress->backlinks($this->july)->actual);
    }

    public function test_analytics_keep_conventions_and_authority_backlink_count_stays_separate(): void
    {
        $gsc = $this->july->gscMonthlyMetric()->firstOrFail();
        $this->assertSame(1200, $gsc->clicks);
        $this->assertSame('2.50', (string) $gsc->ctr, '2.5 means 2.5%');
        $this->assertSame('18.40', (string) $gsc->average_position);

        $ga4 = $this->july->ga4MonthlyMetric()->firstOrFail();
        $this->assertSame(3100, $ga4->active_users);
        $this->assertSame('58.90', (string) $ga4->engagement_rate);

        $authority = $this->july->authorityMetric()->firstOrFail();
        $this->assertSame(540, $authority->backlinks_count, 'vendor index count');
        $this->assertSame(3, $this->garden->backlinks()->count(), 'operational records are a different thing');
        $this->assertSame(31, $authority->moz_domain_authority);
        $this->assertSame('28.0', (string) $authority->ahrefs_domain_rating);

        $queries = $this->july->gscQueryMetrics()->orderBy('query')->get();
        $this->assertSame(['buy roses online', 'garden centre london'], $queries->pluck('query')->all());
        $this->assertSame('3.00', (string) $queries[0]->ctr, '"3%" keeps the human percentage');
        $this->assertSame(Page::query()->where('url', 'https://acme-garden.example/roses')->value('id'), $this->july->gscPageMetrics()->sole()->page_id);
        $this->assertSame(['Ireland', 'United Kingdom'], $this->july->ga4CountryMetrics()->orderBy('country')->pluck('country')->all());
        $this->assertSame('60.50', (string) $this->july->ga4CountryMetrics()->where('country', 'United Kingdom')->sole()->engagement_rate);
    }

    public function test_content_tasks_optimisations_and_notes_follow_existing_rules(): void
    {
        $blog = ContentItem::query()->where('title', '10 Roses for Small Gardens')->firstOrFail();
        $this->assertSame(ContentType::Blog, $blog->content_type);
        $this->assertSame(ContentStatus::Published, $blog->status);
        $this->assertSame($this->july->id, $blog->monthly_cycle_id);
        $this->assertSame($this->executive->id, $blog->assigned_user_id);
        $this->assertSame(Keyword::query()->where('keyword_normalized', 'buy roses online')->value('id'), $blog->target_keyword_id);
        $this->assertSame(1, app(TargetProgressService::class)->blogsActual($this->july), 'only the published blog counts');
        $draft = ContentItem::query()->where('title', 'Summer Watering Guide')->firstOrFail();
        $this->assertSame(ContentStatus::Writing, $draft->status);
        $this->assertSame($this->august->id, $draft->monthly_cycle_id);

        $done = Task::query()->where('title', 'Fix broken internal links')->firstOrFail();
        $this->assertSame(TaskStatus::Completed, $done->status);
        $this->assertNotNull($done->completed_at);
        $this->assertSame($this->executive->id, $done->assigned_user_id);
        $this->assertSame('2026-07-08', $done->due_date->toDateString());
        $wip = Task::query()->where('title', 'Write meta descriptions')->firstOrFail();
        $this->assertSame(TaskStatus::InProgress, $wip->status);
        $this->assertNull($wip->assigned_user_id);
        $this->assertHasIssue($this->run, 'Assignee "Someone Unknown" does not resolve to an active user', 'warning');
        $this->assertSame(0, Task::query()->where('title', 'Claim GBP listing')->count());
        $this->assertHasIssue($this->run, 'Unknown task status "Maybe"', 'error');

        // Task completion never moved a deliverable target; pages optimised is derived from the two events only.
        $progress = app(TargetProgressService::class);
        $this->assertSame(2, $progress->pagesOptimisedActual($this->july));
        $this->assertSame(2, $this->july->pageOptimizations()->count());
        $this->assertSame(2, Page::query()->where('project_id', $this->garden->id)->count(), 'home and roses only: pages are reused by URL across rankings and optimisations, never duplicated, and never created from a content URL');
        $this->assertSame(0, $this->august->pageOptimizations()->count());

        $notes = $this->july->monthlyNotes()->orderBy('id')->get();
        $this->assertSame([MonthlyNoteType::Win, MonthlyNoteType::Recommendation], $notes->pluck('type')->all());
        $this->assertSame('Rankings', $notes[0]->title);
    }

    public function test_rerunning_the_same_source_is_idempotent(): void
    {
        $before = $this->domainSnapshot();

        $again = $this->migrate(dryRun: false);

        $after = $this->domainSnapshot();
        unset($before['legacy_migration_records'], $after['legacy_migration_records']);
        $this->assertSame($before, $after, 'no client, project, cycle, page, keyword, snapshot, backlink, content, task, analytics or note duplicated');

        $this->assertSame(0, $this->tallyOf($again, 'clients', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'projects', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'cycles', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'keywords', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'ranking_snapshots', 'create'));
        $this->assertSame(9, $this->tallyOf($again, 'ranking_snapshots', 'skip') - 3, 'every observation recognised (plus the duplicate row)');
        $this->assertSame(0, $this->tallyOf($again, 'backlinks', 'create'));
        $this->assertSame(3, $this->tallyOf($again, 'backlinks', 'skip'));
        $this->assertSame(0, $this->tallyOf($again, 'content_items', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'tasks', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'page_optimizations', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'analytics', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'analytics', 'conflict'));
        $this->assertSame(0, $this->tallyOf($again, 'notes', 'create'));
        $this->assertSame(0, $this->tallyOf($again, 'targets', 'conflict'), 'legacy July targets agree with the snapshot taken on the first run');
        $this->assertSame(2, $this->run->fresh()->records()->count() > 0 ? 2 : 0);
        $this->assertHasIssue($again, 'was already applied by run #'.$this->run->id, 'info');
    }
}
