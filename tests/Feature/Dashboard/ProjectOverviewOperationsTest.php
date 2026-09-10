<?php

namespace Tests\Feature\Dashboard;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Enums\BacklinkStatus;
use App\Enums\ContentType;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Backlink;
use App\Models\ContentItem;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProjectOverviewOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->executive = User::factory()->seoExecutive()->create();
        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 4],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4],
        ])->create();
        $this->project = Project::factory()->withPackage($package)->ownedBy($this->executive)->create(['name' => 'Casa Botanica']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        Backlink::factory()->count(4)->forCycle($this->september)->status(BacklinkStatus::Live)->create();
        ContentItem::factory()->count(2)->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        Task::factory()->forCycle($this->september)->due('2026-09-01')->create();
        Task::factory()->forCycle($this->september)->create();
        app(EnsureMonthlyReportAction::class)->handle($this->september);
    }

    public function test_overview_shows_completion_tasks_progress_and_report_state_with_links(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertOk()
            ->assertSee('Operations this month')
            ->assertSee('data-operations-completion="75"', false) // backlinks 100 + blogs 50 → 75
            ->assertSee('1 of 2 targets met')
            ->assertSee('data-operations-open-tasks="2"', false)
            ->assertSee('data-operations-overdue-tasks="1"', false)
            ->assertSee('data-operations-backlinks>4 / 4<', false)
            ->assertSee('data-operations-blogs>2 / 4<', false)
            ->assertSee('data-operations-report-status="draft"', false)
            ->assertSee('data-operations-readiness="', false)
            ->assertSee('data-operations-target="blogs" data-percentage="50" data-contribution="50"', false)
            ->assertSee(ProjectResource::getUrl('tasks', ['record' => $this->project, 'cycle' => $this->september]))
            ->assertSee(ProjectResource::getUrl('backlinks', ['record' => $this->project, 'cycle' => $this->september]))
            ->assertSee(ProjectResource::getUrl('report', ['record' => $this->project, 'report' => $this->september->monthlyReport]))
            ->assertDontSee('data-gsc-summary', false)
            ->assertDontSee('createBacklink');
    }

    public function test_overview_flags_a_missing_cycle_without_creating_it(): void
    {
        $bare = Project::factory()->ownedBy($this->executive)->create();

        $this->actingAs(User::factory()->seoManager()->create());

        $this->get(ProjectResource::getUrl('view', ['record' => $bare]))->assertOk()
            ->assertSee('data-operations-missing-cycle', false)
            ->assertSee('No monthly cycle for September 2026.')
            ->assertSee('data-operations-cycle-status="none"', false)
            ->assertSee(ProjectResource::getUrl('monthly-cycles', ['record' => $bare]));

        $this->assertSame(0, $bare->monthlyCycles()->count());
    }

    public function test_overview_respects_project_authorization(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);

        $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertNotFound();
    }
}
