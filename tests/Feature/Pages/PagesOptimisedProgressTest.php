<?php

namespace Tests\Feature\Pages;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Actions\Projects\SyncProjectTargetOverridesAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\SetTaskStatusAction;
use App\Enums\TaskStatus;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PagesOptimisedProgressTest extends TestCase
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
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 8],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4],
        ])->create();
        $this->project = Project::factory()->withPackage($this->package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->progress = app(TargetProgressService::class);
    }

    protected function optimise(Page $page, MonthlyCycle $cycle, string $at = '2026-09-10 10:00:00'): void
    {
        app(RecordPageOptimizationAction::class)->handle($this->project, [
            'page_id' => $page->id,
            'monthly_cycle_id' => $cycle->id,
            'optimized_at' => $at,
            'content_updated' => true,
        ], $this->manager);
    }

    public function test_actual_counts_distinct_pages_so_the_same_page_twice_counts_once(): void
    {
        [$a, $b] = Page::factory()->count(2)->forProject($this->project)->create();

        $this->assertSame(0, $this->progress->pagesOptimisedActual($this->september));

        $this->optimise($a, $this->september, '2026-09-04 09:00:00');
        $this->optimise($a, $this->september, '2026-09-20 09:00:00');
        $this->assertSame(1, $this->progress->pagesOptimisedActual($this->september));
        $this->assertSame(2, $this->september->pageOptimizations()->count());

        $this->optimise($b, $this->september);
        $this->assertSame(2, $this->progress->pagesOptimisedActual($this->september));
        $this->assertSame(3, $this->september->pageOptimizations()->count());

        $result = $this->progress->pagesOptimised($this->september);

        $this->assertSame('pages_optimized', $result->targetKey);
        $this->assertSame('Pages Optimised', $result->label);
        $this->assertSame(2, $result->actual);
        $this->assertSame(8, $result->target);
        $this->assertSame(25, $result->percentage());
        $this->assertSame('2 / 8', $result->format());
    }

    public function test_the_target_comes_from_the_cycle_snapshot_not_the_live_package_or_override(): void
    {
        $this->optimise(Page::factory()->forProject($this->project)->create(), $this->september);

        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised (new)', 'target_value' => 12],
        ]);
        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['pages_optimized' => 20]);

        $result = $this->progress->pagesOptimised($this->september);

        $this->assertSame(8, $result->target);
        $this->assertSame('Pages Optimised', $result->label);
        $this->assertSame('1 / 8', $result->format());

        // A new cycle picks up the new configuration; September is untouched.
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project->fresh(), new CyclePeriod(2026, 10));

        $this->assertSame(20, $this->progress->pagesOptimised($october)->target);
        $this->assertSame(8, $this->progress->pagesOptimised($this->september->fresh())->target);
    }

    public function test_each_historical_cycle_uses_its_own_records_and_snapshot(): void
    {
        $page = Page::factory()->forProject($this->project)->create();
        $this->optimise($page, $this->september);
        $this->optimise(Page::factory()->forProject($this->project)->create(), $this->september);

        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['pages_optimized' => 3]);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project->fresh(), new CyclePeriod(2026, 10));
        $this->optimise($page, $october, '2026-10-02 10:00:00');

        $this->assertSame('2 / 8', $this->progress->pagesOptimised($this->september)->format());
        $this->assertSame('1 / 3', $this->progress->pagesOptimised($october)->format());
    }

    public function test_a_cycle_without_a_pages_optimised_snapshot_reports_no_target_not_zero(): void
    {
        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        $page = Page::factory()->forProject($bare)->create();

        app(RecordPageOptimizationAction::class)->handle($bare, [
            'page_id' => $page->id,
            'monthly_cycle_id' => $cycle->id,
            'meta_title_updated' => true,
        ], $this->manager);

        $result = $this->progress->pagesOptimised($cycle);

        $this->assertSame(1, $result->actual);
        $this->assertNull($result->target);
        $this->assertFalse($result->hasTarget());
        $this->assertNull($result->percentage());
        $this->assertSame('1 / No target', $result->format());
        $this->assertNotSame(0, $result->target);
    }

    public function test_other_target_keys_have_no_actual_yet(): void
    {
        // Blogs gained a derived actual in Milestone 10 (published blog content).
        $blogs = $this->progress->progressFor($this->september, 'blogs');

        $this->assertSame(0, $blogs->actual);
        $this->assertSame(4, $blogs->target);
        $this->assertSame('0 / 4', $blogs->format());

        // Keys without a derivation still report no actual rather than a fake zero.
        $unknown = $this->progress->progressFor($this->september, 'site_audits');

        $this->assertNull($unknown->actual);
        $this->assertNull($unknown->target);
        $this->assertNull($unknown->percentage());
    }

    public function test_task_completion_has_no_effect_on_pages_optimised(): void
    {
        $page = Page::factory()->forProject($this->project)->create();
        $this->optimise($page, $this->september);

        $task = app(CreateTaskAction::class)->handle($this->project, [
            'title' => 'Optimise 5 pages',
            'monthly_cycle_id' => $this->september->id,
        ], $this->manager);
        app(SetTaskStatusAction::class)->handle($task, TaskStatus::Completed);

        $this->assertSame(1, $this->progress->pagesOptimisedActual($this->september));
        $this->assertSame('1 / 8', $this->progress->pagesOptimised($this->september)->format());
    }
}
