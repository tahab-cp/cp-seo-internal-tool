<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Analytics\SaveAuthorityMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Actions\Projects\SyncProjectTargetOverridesAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\SetTaskStatusAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\ContentType;
use App\Enums\TaskStatus;
use App\Models\Backlink;
use App\Models\ContentItem;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Services\MonthlyCycles\MonthlyTargetCompletionService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Targets\MonthlyTargetCompletion;
use App\Support\Targets\TargetCompletionRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Monthly Target Completion = average of capped contributions of every
 * participating target from the cycle's snapshot. Never a score.
 */
class MonthlyTargetCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Package $package;

    protected Project $project;

    protected MonthlyCycle $september;

    protected MonthlyTargetCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 4],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 2],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4],
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 2],
            ['target_key' => 'site_audits', 'label' => 'Site Audits', 'target_value' => 1],
        ])->create();
        $this->project = Project::factory()->withPackage($this->package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->service = app(MonthlyTargetCompletionService::class);
    }

    protected function row(MonthlyTargetCompletion $completion, string $key): TargetCompletionRow
    {
        return $completion->rows->first(fn (TargetCompletionRow $row): bool => $row->targetKey === $key);
    }

    protected function fillSeptember(): void
    {
        // 3 live links (2 citations + 1 guest post) → backlinks 3/4, guest posts 1/2.
        Backlink::factory()->count(2)->forCycle($this->september)->type(BacklinkType::Citation)->status(BacklinkStatus::Live)->create();
        Backlink::factory()->forCycle($this->september)->guestPost()->status(BacklinkStatus::Live)->create();
        Backlink::factory()->forCycle($this->september)->guestPost()->status(BacklinkStatus::Submitted)->create();
        // 3 published blogs → 3/4; a draft and a published landing page do not count.
        ContentItem::factory()->count(3)->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->create();
        ContentItem::factory()->forCycle($this->september)->type(ContentType::LandingPage)->published()->create();
        // 2 distinct pages optimised (one twice) → 2/2.
        [$a, $b] = Page::factory()->count(2)->forProject($this->project)->create();
        foreach ([$a, $a, $b] as $i => $page) {
            app(RecordPageOptimizationAction::class)->handle($this->project, ['page_id' => $page->id, 'monthly_cycle_id' => $this->september->id, 'optimized_at' => '2026-09-0'.($i + 1).' 10:00:00', 'content_updated' => true], $this->manager);
        }
    }

    public function test_every_supported_target_participates_and_unsupported_keys_are_excluded(): void
    {
        $this->fillSeptember();
        $completion = $this->service->for($this->september);

        $this->assertSame(['backlinks', 'guest_posts', 'blogs', 'pages_optimized', 'site_audits'], $completion->rows->map(fn ($r) => $r->targetKey)->all());
        $this->assertSame(['backlinks', 'guest_posts', 'blogs', 'pages_optimized'], $completion->participating()->map(fn ($r) => $r->targetKey)->all());

        $this->assertSame(75.0, $this->row($completion, 'backlinks')->percentage());
        $this->assertSame(50.0, $this->row($completion, 'guest_posts')->percentage());
        $this->assertSame(75.0, $this->row($completion, 'blogs')->percentage());
        $this->assertSame(100.0, $this->row($completion, 'pages_optimized')->percentage());

        $audits = $this->row($completion, 'site_audits');
        $this->assertFalse($audits->supported);
        $this->assertFalse($audits->participates());
        $this->assertNull($audits->percentage());
        $this->assertNull($audits->contribution());

        $this->assertSame(75.0, $completion->overall());
        $this->assertSame(75, $completion->overallPercentage());
        $this->assertSame('75%', $completion->label());
        $this->assertSame(1, $completion->metCount());
    }

    public function test_missing_snapshot_rows_and_zero_targets_are_excluded_without_dividing_by_zero(): void
    {
        // A cycle whose snapshot has only two keys, one of them zero.
        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        $cycle->targets()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 0]);
        $cycle->targets()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 2]);
        ContentItem::factory()->forCycle($cycle)->type(ContentType::Blog)->published()->create();
        Backlink::factory()->forCycle($cycle)->status(BacklinkStatus::Live)->create();

        $completion = $this->service->for($cycle);

        $this->assertCount(2, $completion->rows);
        $this->assertNull($completion->rows->first(fn ($r) => $r->targetKey === 'guest_posts'), 'Keys without a snapshot row do not appear.');

        $blogs = $this->row($completion, 'blogs');
        $this->assertTrue($blogs->supported);
        $this->assertSame(1, $blogs->actual);
        $this->assertFalse($blogs->participates(), 'A zero target represents no positive requirement.');
        $this->assertNull($blogs->percentage());
        $this->assertNull($blogs->contribution());

        // Only backlinks participate: 1 / 2 = 50%, not dragged down by a fake 0%.
        $this->assertSame(50.0, $completion->overall());
        $this->assertSame(['backlinks'], $completion->participating()->map(fn ($r) => $r->targetKey)->all());

        // No snapshot at all → no figure, never 0%.
        $empty = app(CreateMonthlyCycleAction::class)->handle(Project::factory()->create(), new CyclePeriod(2026, 9));
        $none = $this->service->for($empty);
        $this->assertFalse($none->hasParticipatingTargets());
        $this->assertNull($none->overall());
        $this->assertNull($none->overallPercentage());
        $this->assertSame('No targets', $none->label());
    }

    public function test_individual_percentages_are_uncapped_but_contributions_cap_at_100(): void
    {
        $this->september->targets()->delete();
        $this->september->targets()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4]);
        $this->september->targets()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50]);

        ContentItem::factory()->count(2)->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        $this->assertSame(50.0, $this->row($this->service->for($this->september), 'blogs')->percentage()); // 2 / 4

        ContentItem::factory()->count(2)->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        $this->assertSame(100.0, $this->row($this->service->for($this->september), 'blogs')->percentage()); // 4 / 4

        ContentItem::factory()->count(2)->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        $blogs = $this->row($this->service->for($this->september), 'blogs'); // 6 / 4
        $this->assertSame(150.0, $blogs->percentage());
        $this->assertSame('150%', $blogs->percentageLabel());
        $this->assertSame('6 / 4', $blogs->display());
        $this->assertSame(100.0, $blogs->contribution());

        // Blogs at 150% cannot compensate for backlinks at 0%: overall is 50%, not 75%.
        $completion = $this->service->for($this->september);
        $this->assertSame(0.0, $this->row($completion, 'backlinks')->percentage());
        $this->assertSame(50.0, $completion->overall());
    }

    public function test_equal_weighting_keeps_full_precision(): void
    {
        $this->september->targets()->delete();
        foreach ([['backlinks', 4], ['guest_posts', 2], ['blogs', 4], ['pages_optimized', 1]] as [$key, $target]) {
            $this->september->targets()->create(['target_key' => $key, 'label' => $key, 'target_value' => $target]);
        }

        // backlinks 4/4 = 100, guest posts 1/2 = 50, blogs 3/4 = 75, pages 1/1 = 100 → 81.25
        Backlink::factory()->count(3)->forCycle($this->september)->type(BacklinkType::Citation)->status(BacklinkStatus::Live)->create();
        Backlink::factory()->forCycle($this->september)->guestPost()->status(BacklinkStatus::Live)->create();
        ContentItem::factory()->count(3)->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        app(RecordPageOptimizationAction::class)->handle($this->project, ['page_id' => Page::factory()->forProject($this->project)->create()->id, 'monthly_cycle_id' => $this->september->id, 'content_updated' => true], $this->manager);

        $completion = $this->service->for($this->september);

        $this->assertSame([100.0, 50.0, 75.0, 100.0], $completion->participating()->map(fn ($r) => $r->contribution())->all());
        $this->assertSame(81.25, $completion->overall());
        $this->assertSame(81, $completion->overallPercentage());
        $this->assertSame(81.25, $completion->toArray()['overall']);
    }

    public function test_historical_cycles_use_their_own_snapshot_regardless_of_later_package_or_override_changes(): void
    {
        $this->fillSeptember();
        $before = $this->service->for($this->september);
        $this->assertSame(75.0, $before->overall());

        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 100],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 1],
        ]);
        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['backlinks' => 1]);

        $after = $this->service->for($this->september->fresh());
        $this->assertSame(75.0, $after->overall());
        $this->assertSame(4, $this->row($after, 'backlinks')->target);

        // A new month snapshots the new configuration (override 1 wins over package 100).
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project->fresh(), new CyclePeriod(2026, 10));
        $next = $this->service->for($october);
        $this->assertSame(1, $this->row($next, 'backlinks')->target);
        $this->assertSame(1, $this->row($next, 'blogs')->target);
        $this->assertSame(0.0, $next->overall());
    }

    public function test_tasks_authority_and_analytics_never_move_target_completion(): void
    {
        $this->fillSeptember();

        $task = app(CreateTaskAction::class)->handle($this->project, ['title' => 'Build 10 links', 'monthly_cycle_id' => $this->september->id], $this->manager);
        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);
        app(SaveAuthorityMetricsAction::class)->handle($this->september, ['backlinks_count' => 99999, 'referring_domains_count' => 5000], $this->manager);
        app(SaveGscMonthlyMetricsAction::class)->handle($this->september, ['clicks' => 5000, 'impressions' => 90000], $this->manager);

        $completion = $this->service->for($this->september->fresh());

        $this->assertSame(75.0, $completion->overall());
        $this->assertSame(3, $this->row($completion, 'backlinks')->actual);
    }

    public function test_batch_and_single_evaluation_agree(): void
    {
        $this->fillSeptember();
        $other = app(CreateMonthlyCycleAction::class)->handle(Project::factory()->withPackage($this->package)->create(), new CyclePeriod(2026, 9));

        $batch = $this->service->forCycles(collect([$this->september, $other]));

        $this->assertSame(75.0, $batch->get($this->september->id)->overall());
        $this->assertSame(0.0, $batch->get($other->id)->overall());
        $this->assertEquals($this->service->for($this->september)->toArray(), $batch->get($this->september->id)->toArray());
    }
}
