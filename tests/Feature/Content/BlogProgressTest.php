<?php

namespace Tests\Feature\Content;

use App\Actions\Backlinks\SetBacklinkStatusAction;
use App\Actions\Content\CreateContentItemAction;
use App\Actions\Content\SetContentStatusAction;
use App\Actions\Content\UpdateContentItemAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Actions\Projects\SyncProjectTargetOverridesAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\SetTaskStatusAction;
use App\Enums\BacklinkStatus;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\MonthlyCycleStatus;
use App\Enums\TaskStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Models\Backlink;
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
use InvalidArgumentException;
use Tests\TestCase;

class BlogProgressTest extends TestCase
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
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create();
        $this->project = Project::factory()->withPackage($this->package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->progress = app(TargetProgressService::class);
    }

    protected function item(ContentType $type, ContentStatus $status, ?MonthlyCycle $cycle = null): ContentItem
    {
        $factory = ContentItem::factory()->forCycle($cycle ?? $this->september)->type($type);

        return $status === ContentStatus::Published
            ? $factory->published()->create()
            : $factory->status($status)->create();
    }

    public function test_only_published_blogs_count(): void
    {
        foreach (ContentStatus::cases() as $status) {
            $this->item(ContentType::Blog, $status);
        }

        $this->assertSame(1, $this->progress->blogsActual($this->september));

        foreach ([ContentType::LandingPage, ContentType::ServicePage, ContentType::LocationPage, ContentType::GuestContent, ContentType::Other] as $type) {
            $this->item($type, ContentStatus::Published);
        }

        $this->assertSame(1, $this->progress->blogsActual($this->september));

        // Project-level (unscheduled) published-looking rows never count.
        ContentItem::factory()->forProject($this->project)->type(ContentType::Blog)->status(ContentStatus::Published)->create();
        $this->assertSame(1, $this->progress->blogsActual($this->september));

        $blogs = $this->progress->blogs($this->september);
        $this->assertSame('Blogs', $blogs->label);
        $this->assertSame('1 / 8', $blogs->format());
        $this->assertSame(7, $blogs->remaining());
    }

    public function test_status_and_type_changes_are_reflected_immediately(): void
    {
        $item = $this->item(ContentType::Blog, ContentStatus::Approved);
        $this->assertSame(0, $this->progress->blogsActual($this->september));

        app(SetContentStatusAction::class)->handle($item, ContentStatus::Published, ['published_url' => 'https://site.example/blog/a']);
        $this->assertSame(1, $this->progress->blogsActual($this->september));

        app(SetContentStatusAction::class)->handle($item, ContentStatus::Review);
        $this->assertSame(0, $this->progress->blogsActual($this->september));

        app(SetContentStatusAction::class)->handle($item, ContentStatus::Published, ['published_url' => 'https://site.example/blog/a']);
        $this->assertSame(1, $this->progress->blogsActual($this->september));

        app(UpdateContentItemAction::class)->handle($item, ['content_type' => 'landing_page']);
        $this->assertSame(0, $this->progress->blogsActual($this->september));

        app(UpdateContentItemAction::class)->handle($item, ['content_type' => 'blog']);
        $this->assertSame(1, $this->progress->blogsActual($this->september));

        $item->delete();
        $this->assertSame(0, $this->progress->blogsActual($this->september));
    }

    public function test_target_comes_from_the_snapshot_not_the_live_package_or_override(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->item(ContentType::Blog, ContentStatus::Published);
        }

        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 12],
        ]);
        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['blogs' => 20]);

        $this->assertSame('6 / 8', $this->progress->blogs($this->september)->format());
        $this->assertSame('2 remaining', $this->progress->blogs($this->september)->remainingLabel());

        $october = app(CreateMonthlyCycleAction::class)->handle($this->project->fresh(), new CyclePeriod(2026, 10));
        $this->item(ContentType::Blog, ContentStatus::Published, $october);

        $this->assertSame('1 / 20', $this->progress->blogs($october)->format());
        $this->assertSame('6 / 8', $this->progress->blogs($this->september->fresh())->format());
    }

    public function test_missing_target_and_over_target(): void
    {
        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        ContentItem::factory()->count(3)->forCycle($cycle)->type(ContentType::Blog)->published()->create();

        $blogs = $this->progress->blogs($cycle);
        $this->assertSame(3, $blogs->actual);
        $this->assertNull($blogs->target);
        $this->assertNull($blogs->remaining());
        $this->assertSame('3 / No target', $blogs->format());

        for ($i = 0; $i < 10; $i++) {
            $this->item(ContentType::Blog, ContentStatus::Published);
        }

        $over = $this->progress->blogs($this->september);
        $this->assertSame('10 / 8', $over->format());
        $this->assertSame(0, $over->remaining());
        $this->assertSame('2 over target', $over->remainingLabel());
        $this->assertSame(125, $over->percentage());
    }

    public function test_moving_a_published_blog_between_unlocked_cycles_reattributes_it_intact(): void
    {
        $octoberSnapshotTargetBefore = 8;
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));

        // Later package/override changes must not touch either month's snapshot.
        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 12],
        ]);
        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['blogs' => 20]);

        $post = app(CreateContentItemAction::class)->handle($this->project, [
            'title' => 'Moved post',
            'content_type' => 'blog',
            'status' => 'published',
            'monthly_cycle_id' => $this->september->id,
            'published_at' => '2026-09-28 09:00',
            'published_url' => 'https://site.example/blog/moved',
        ]);

        $this->assertSame('1 / 8', $this->progress->blogs($this->september)->format());
        $this->assertSame('0 / '.$octoberSnapshotTargetBefore, $this->progress->blogs($october)->format());

        // Both cycles must belong to the item's project.
        try {
            app(UpdateContentItemAction::class)->handle($post, ['monthly_cycle_id' => MonthlyCycle::factory()->create()->id]);
            $this->fail('Expected a foreign cycle to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        // Intentional move while both cycles are unlocked.
        app(UpdateContentItemAction::class)->handle($post, ['monthly_cycle_id' => $october->id]);

        $moved = $post->fresh();
        $this->assertSame($october->id, $moved->monthly_cycle_id);
        $this->assertTrue($moved->isPublished());
        $this->assertSame('2026-09-28 09:00:00', $moved->published_at->toDateTimeString());
        $this->assertSame('https://site.example/blog/moved', $moved->published_url);
        $this->assertSame('0 / 8', $this->progress->blogs($this->september->fresh())->format());
        $this->assertSame('1 / 8', $this->progress->blogs($october->fresh())->format());

        // Locked destination: cannot move a published item into it.
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        try {
            app(UpdateContentItemAction::class)->handle($moved, ['monthly_cycle_id' => $this->september->id]);
            $this->fail('Expected LockedMonthlyCycleException for the locked destination.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($october->id, $moved->fresh()->monthly_cycle_id);

        // Locked source: cannot move a published item out of it.
        $this->september->forceFill(['status' => MonthlyCycleStatus::Open, 'locked_at' => null])->save();
        $october->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        try {
            app(UpdateContentItemAction::class)->handle($moved->fresh(), ['monthly_cycle_id' => $this->september->id]);
            $this->fail('Expected LockedMonthlyCycleException for the locked source.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $final = $moved->fresh();
        $this->assertSame($october->id, $final->monthly_cycle_id);
        $this->assertSame('https://site.example/blog/moved', $final->published_url);
        $this->assertSame('1 / 8', $this->progress->blogs($october->fresh())->format());
        $this->assertSame('0 / 8', $this->progress->blogs($this->september->fresh())->format());
    }

    public function test_other_modules_have_no_effect_on_blogs(): void
    {
        $this->item(ContentType::Blog, ContentStatus::Published);

        $task = app(CreateTaskAction::class)->handle($this->project, ['title' => 'Write 5 blogs', 'monthly_cycle_id' => $this->september->id], $this->manager);
        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);

        $link = Backlink::factory()->forCycle($this->september)->status(BacklinkStatus::Submitted)->guestPost()->create();
        app(SetBacklinkStatusAction::class)->handle($link, BacklinkStatus::Live);

        app(RecordPageOptimizationAction::class)->handle($this->project, [
            'page_id' => Page::factory()->forProject($this->project)->create()->id,
            'monthly_cycle_id' => $this->september->id,
            'content_updated' => true,
        ], $this->manager);

        RankingSnapshot::factory()->forKeyword(Keyword::factory()->forProject($this->project)->create())->forCycle($this->september)->at('2026-09-10 09:00', 4)->create();

        $this->assertSame('1 / 8', $this->progress->blogs($this->september)->format());
    }
}
