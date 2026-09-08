<?php

namespace Tests\Feature\Backlinks;

use App\Actions\Backlinks\SetBacklinkStatusAction;
use App\Actions\Backlinks\UpdateBacklinkAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Actions\Projects\SyncProjectTargetOverridesAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\SetTaskStatusAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\TaskStatus;
use App\Models\Backlink;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Targets\TargetProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BacklinkProgressTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Package $package;

    protected Project $project;

    protected MonthlyCycle $september;

    protected TargetProgressService $progress;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 8],
        ])->create();
        $this->project = Project::factory()->withPackage($this->package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->progress = app(TargetProgressService::class);
    }

    protected function link(BacklinkStatus $status, BacklinkType $type = BacklinkType::Citation, ?MonthlyCycle $cycle = null): Backlink
    {
        return Backlink::factory()->forCycle($cycle ?? $this->september)->status($status)->type($type)->create();
    }

    public function test_only_live_links_count_and_live_guest_posts_count_twice(): void
    {
        foreach (BacklinkStatus::cases() as $status) {
            $this->link($status);
        }

        $this->assertSame(1, $this->progress->backlinksActual($this->september));
        $this->assertSame(0, $this->progress->guestPostsActual($this->september));

        $this->link(BacklinkStatus::Live, BacklinkType::GuestPost);
        $this->link(BacklinkStatus::Submitted, BacklinkType::GuestPost);

        $this->assertSame(2, $this->progress->backlinksActual($this->september));
        $this->assertSame(1, $this->progress->guestPostsActual($this->september));
    }

    public function test_the_documented_worked_example(): void
    {
        Backlink::factory()->count(20)->forCycle($this->september)->status(BacklinkStatus::Live)->create();
        Backlink::factory()->count(5)->forCycle($this->september)->status(BacklinkStatus::Live)->guestPost()->create();
        Backlink::factory()->count(3)->forCycle($this->september)->status(BacklinkStatus::Submitted)->guestPost()->create();
        Backlink::factory()->count(2)->forCycle($this->september)->status(BacklinkStatus::Removed)->create();

        $backlinks = $this->progress->backlinks($this->september);
        $guestPosts = $this->progress->guestPosts($this->september);

        $this->assertSame(25, $backlinks->actual);
        $this->assertSame(50, $backlinks->target);
        $this->assertSame('25 / 50', $backlinks->format());
        $this->assertSame(25, $backlinks->remaining());
        $this->assertSame('25 remaining', $backlinks->remainingLabel());

        $this->assertSame(5, $guestPosts->actual);
        $this->assertSame(8, $guestPosts->target);
        $this->assertSame('5 / 8', $guestPosts->format());
        $this->assertSame(3, $guestPosts->remaining());
        $this->assertSame('3 remaining', $guestPosts->remainingLabel());

        $this->assertSame(
            ['guest_post' => 5, 'citation' => 20, 'profile' => 0, 'forum' => 0, 'blog_comment' => 0, 'directory' => 0, 'outreach' => 0, 'other' => 0],
            $this->progress->liveBacklinkTypeBreakdown($this->september),
        );
    }

    public function test_status_and_type_changes_are_reflected_immediately(): void
    {
        $link = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Submitted)->guestPost()->create();

        $this->assertSame(0, $this->progress->backlinksActual($this->september));
        $this->assertSame(0, $this->progress->guestPostsActual($this->september));

        app(SetBacklinkStatusAction::class)->handle($link, BacklinkStatus::Live);
        $this->assertSame(1, $this->progress->backlinksActual($this->september));
        $this->assertSame(1, $this->progress->guestPostsActual($this->september));

        // live guest_post → live citation: still a backlink, no longer a guest post.
        app(UpdateBacklinkAction::class)->handle($link, ['type' => 'citation']);
        $this->assertSame(1, $this->progress->backlinksActual($this->september));
        $this->assertSame(0, $this->progress->guestPostsActual($this->september));

        app(SetBacklinkStatusAction::class)->handle($link, BacklinkStatus::Removed);
        $this->assertSame(0, $this->progress->backlinksActual($this->september));
        $this->assertSame(0, $this->progress->guestPostsActual($this->september));

        // Soft-deleted rows never count either.
        $live = $this->link(BacklinkStatus::Live);
        $this->assertSame(1, $this->progress->backlinksActual($this->september));
        $live->delete();
        $this->assertSame(0, $this->progress->backlinksActual($this->september));
    }

    public function test_targets_come_from_the_snapshot_not_the_live_package_or_override(): void
    {
        $this->link(BacklinkStatus::Live);

        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 70],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 12],
        ]);
        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['backlinks' => 99, 'guest_posts' => 20]);

        $this->assertSame('1 / 50', $this->progress->backlinks($this->september)->format());
        $this->assertSame('0 / 8', $this->progress->guestPosts($this->september)->format());

        $october = app(CreateMonthlyCycleAction::class)->handle($this->project->fresh(), new CyclePeriod(2026, 10));

        $this->assertSame(99, $this->progress->backlinks($october)->target);
        $this->assertSame(20, $this->progress->guestPosts($october)->target);
        $this->assertSame(50, $this->progress->backlinks($this->september->fresh())->target);
    }

    public function test_each_cycle_uses_only_its_own_records_and_snapshots(): void
    {
        $this->link(BacklinkStatus::Live);
        $this->link(BacklinkStatus::Live);

        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['backlinks' => 10]);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project->fresh(), new CyclePeriod(2026, 10));
        $this->link(BacklinkStatus::Live, BacklinkType::GuestPost, $october);

        $this->assertSame('2 / 50', $this->progress->backlinks($this->september)->format());
        $this->assertSame('1 / 10', $this->progress->backlinks($october)->format());
        $this->assertSame('0 / 8', $this->progress->guestPosts($this->september)->format());
        $this->assertSame('1 / 8', $this->progress->guestPosts($october)->format());
    }

    public function test_missing_target_snapshots_report_no_target_not_zero(): void
    {
        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        Backlink::factory()->count(12)->forCycle($cycle)->status(BacklinkStatus::Live)->create();
        Backlink::factory()->forCycle($cycle)->status(BacklinkStatus::Live)->guestPost()->create();

        $backlinks = $this->progress->backlinks($cycle);
        $guestPosts = $this->progress->guestPosts($cycle);

        $this->assertSame(13, $backlinks->actual);
        $this->assertNull($backlinks->target);
        $this->assertNull($backlinks->remaining());
        $this->assertNull($backlinks->remainingLabel());
        $this->assertSame('13 / No target', $backlinks->format());

        $this->assertSame(1, $guestPosts->actual);
        $this->assertNull($guestPosts->target);
        $this->assertSame('1 / No target', $guestPosts->format());
    }

    public function test_remaining_is_floored_at_zero_and_over_target_is_not_capped(): void
    {
        $under = new TargetProgress('backlinks', 'Backlinks', 32, 50);
        $met = new TargetProgress('backlinks', 'Backlinks', 50, 50);
        $over = new TargetProgress('backlinks', 'Backlinks', 55, 50);

        $this->assertSame(18, $under->remaining());
        $this->assertSame('18 remaining', $under->remainingLabel());
        $this->assertSame(0, $met->remaining());
        $this->assertSame('Target met', $met->remainingLabel());
        $this->assertTrue($met->isComplete());
        $this->assertSame(0, $over->remaining());
        $this->assertSame('5 over target', $over->remainingLabel());
        $this->assertTrue($over->isOverTarget());
        $this->assertSame('55 / 50', $over->format());
        $this->assertSame(110, $over->percentage());
    }

    public function test_tasks_page_optimisations_and_rankings_have_no_effect(): void
    {
        $this->link(BacklinkStatus::Live);
        $this->link(BacklinkStatus::Live, BacklinkType::GuestPost);

        $task = app(CreateTaskAction::class)->handle($this->project, ['title' => 'Build 10 links', 'monthly_cycle_id' => $this->september->id], $this->manager);
        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);

        app(RecordPageOptimizationAction::class)->handle($this->project, [
            'page_id' => Page::factory()->forProject($this->project)->create()->id,
            'monthly_cycle_id' => $this->september->id,
            'internal_links_updated' => true,
        ], $this->manager);

        RankingSnapshot::factory()->forKeyword(Keyword::factory()->forProject($this->project)->create())->forCycle($this->september)->at('2026-09-10 09:00', 3)->create();

        $this->assertSame('2 / 50', $this->progress->backlinks($this->september)->format());
        $this->assertSame('1 / 8', $this->progress->guestPosts($this->september)->format());
    }
}
