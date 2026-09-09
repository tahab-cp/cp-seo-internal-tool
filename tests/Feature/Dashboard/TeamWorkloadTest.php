<?php

namespace Tests\Feature\Dashboard;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Enums\TaskStatus;
use App\Filament\Pages\TeamWorkload;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Dashboard\TeamWorkloadService;
use App\Support\Dashboard\TeamWorkloadRow;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Conventions under test: active users with a role; active projects only;
 * open = pending/in progress/blocked and not soft-deleted; overdue =
 * due < today; due this week = today..Sunday (never overdue); reports in
 * preparation = Draft/Ready reports of the current period on active
 * projects the user owns or belongs to. Inactive users are excluded.
 */
class TeamWorkloadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $manager;

    protected User $eli;

    protected User $nia;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 15 September 2026; week = Mon 14 – Sun 20.
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Ava Admin']);
        $this->manager = User::factory()->seoManager()->create(['name' => 'Morgan Manager']);
        $this->eli = User::factory()->seoExecutive()->create(['name' => 'Eli Executive']);
        $this->nia = User::factory()->seoExecutive()->create(['name' => 'Nia Executive']);
        $inactive = User::factory()->seoExecutive()->inactive()->create(['name' => 'Ivy Inactive']);

        // Eli owns two active projects and one paused one; is a team member on Nia's active project.
        $a = Project::factory()->ownedBy($this->eli)->create(['name' => 'A']);
        $b = Project::factory()->ownedBy($this->eli)->create(['name' => 'B']);
        Project::factory()->ownedBy($this->eli)->paused()->create(['name' => 'Paused']);
        $n = Project::factory()->ownedBy($this->nia)->withTeam([$this->eli])->create(['name' => 'N']);
        $archived = Project::factory()->ownedBy($this->eli)->withTeam([$this->nia])->create(['name' => 'Archived']);

        // Eli's tasks on active projects.
        Task::factory()->forProject($a)->assignedTo($this->eli)->due('2026-09-10')->create();               // overdue
        Task::factory()->forProject($a)->assignedTo($this->eli)->due('2026-09-14')->create();               // overdue (Monday, before today)
        Task::factory()->forProject($b)->assignedTo($this->eli)->due('2026-09-15')->create();               // this week (today)
        Task::factory()->forProject($n)->assignedTo($this->eli)->due('2026-09-20')->create();               // this week (Sunday)
        Task::factory()->forProject($b)->assignedTo($this->eli)->due('2026-09-21')->create();               // next week
        Task::factory()->forProject($b)->assignedTo($this->eli)->create();                                   // undated
        Task::factory()->forProject($a)->assignedTo($this->eli)->status(TaskStatus::Completed)->due('2026-09-01')->create();
        Task::factory()->forProject($a)->assignedTo($this->eli)->status(TaskStatus::Cancelled)->due('2026-09-01')->create();
        Task::factory()->forProject($a)->assignedTo($this->eli)->due('2026-09-01')->create()->delete();
        // Tasks on non-active projects never count.
        Task::factory()->forProject($archived)->assignedTo($this->eli)->due('2026-09-01')->create();
        $archived->delete();
        Task::factory()->forProject(Project::factory()->ownedBy($this->eli)->paused()->create())->assignedTo($this->eli)->due('2026-09-01')->create();
        // The inactive user has work that must not appear.
        Task::factory()->forProject($a)->assignedTo($inactive)->due('2026-09-01')->create();

        // Reports in preparation: A draft (Eli owner), N ready (Nia owner, Eli member), B final (not counted).
        app(EnsureMonthlyReportAction::class)->handle(app(CreateMonthlyCycleAction::class)->handle($a, new CyclePeriod(2026, 9)));
        $nReport = app(EnsureMonthlyReportAction::class)->handle(app(CreateMonthlyCycleAction::class)->handle($n, new CyclePeriod(2026, 9)));
        $nReport->forceFill(['status' => 'ready_for_review'])->save();
        app(EnsureMonthlyReportAction::class)->handle(app(CreateMonthlyCycleAction::class)->handle($b, new CyclePeriod(2026, 9)))->forceFill(['status' => 'final'])->save();
    }

    protected function row(string $name): TeamWorkloadRow
    {
        return app(TeamWorkloadService::class)->rows()->first(fn (TeamWorkloadRow $r): bool => $r->user->name === $name);
    }

    public function test_counts_follow_the_documented_conventions(): void
    {
        $rows = app(TeamWorkloadService::class)->rows();

        $this->assertSame(['Ava Admin', 'Eli Executive', 'Morgan Manager', 'Nia Executive'], $rows->map(fn ($r) => $r->user->name)->all(), 'Active users with a role, by name; the inactive user is excluded.');

        $eli = $this->row('Eli Executive');
        $this->assertSame(2, $eli->primaryProjects, 'Only ACTIVE projects: A and B (paused and archived excluded).');
        $this->assertSame(1, $eli->teamProjects, 'Member of N; the archived membership does not count.');
        $this->assertSame(6, $eli->openTasks, 'Two overdue, two this week, next week, undated; completed/cancelled/deleted/inactive-project tasks excluded.');
        $this->assertSame(2, $eli->overdueTasks);
        $this->assertSame(2, $eli->dueThisWeekTasks, 'Today and Sunday; Monday (overdue) and next Monday are not this week.');
        $this->assertSame(2, $eli->reportsInPreparation, 'A (draft, owner) and N (ready, member); B is final.');
        $this->assertSame('SEO Executive', $eli->roleLabel());

        $nia = $this->row('Nia Executive');
        $this->assertSame(1, $nia->primaryProjects);
        $this->assertSame(0, $nia->teamProjects, 'Her archived membership does not count.');
        $this->assertSame(0, $nia->openTasks);
        $this->assertSame(1, $nia->reportsInPreparation);

        $this->assertSame(0, $this->row('Morgan Manager')->openTasks);
    }

    public function test_only_admin_and_manager_may_open_team_workload(): void
    {
        foreach ([$this->admin, $this->manager] as $user) {
            $this->actingAs($user);
            $this->assertTrue(TeamWorkload::canAccess());

            $this->get(TeamWorkload::getUrl())->assertOk()
                ->assertSee('data-workload-user="'.$this->eli->id.'" data-open="6" data-overdue="2" data-week="2" data-primary="2" data-team="1" data-reports="2"', false)
                ->assertSee('Eli Executive')
                ->assertDontSee('Ivy Inactive')
                ->assertSee('workload indicators, not capacity or performance');

            Livewire::test(TeamWorkload::class)->assertOk();
        }

    }

    public function test_executive_cannot_open_team_workload(): void
    {
        $this->actingAs($this->eli);
        $this->assertFalse(TeamWorkload::canAccess());
        $this->get(TeamWorkload::getUrl())->assertForbidden();
        Livewire::test(TeamWorkload::class)->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee(TeamWorkload::getUrl());
    }

    /**
     * Presentation: summary cards in Filament's responsive grid (1 / sm:2 /
     * xl:4), one card per member with six metrics in a 2 / sm:3 / lg:6
     * grid. Totals are plain sums of the rows the service already returns.
     */
    public function test_team_workload_uses_summary_cards_and_responsive_member_cards(): void
    {
        $this->actingAs($this->admin);
        $rows = app(TeamWorkloadService::class)->rows();
        $html = $this->get(TeamWorkload::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('Current operational workload across active SEO team members.', $html);

        $this->assertSame(1, preg_match('/<div\b[^>]*data-team-workload-summary[^>]*>/s', $html, $summary), 'Summary grid not found.');
        $this->assertStringContainsString('sm:fi-grid-cols', $summary[0]);
        $this->assertStringContainsString('--cols-xl: repeat(4, minmax(0, 1fr))', $summary[0]);

        $this->assertStringContainsString('data-workload-summary="members" data-value="'.$rows->count().'"', $html);
        $this->assertStringContainsString('data-workload-summary="primary" data-value="'.$rows->sum(fn ($r) => $r->primaryProjects).'"', $html);
        $this->assertStringContainsString('data-workload-summary="open" data-value="'.$rows->sum(fn ($r) => $r->openTasks).'"', $html);
        $this->assertStringContainsString('data-workload-summary="overdue" data-value="'.$rows->sum(fn ($r) => $r->overdueTasks).'"', $html);
        $this->assertSame(4, $rows->count());

        $this->assertSame(1, preg_match('/<div\b[^>]*data-team-workload=""[^>]*>/s', $html, $list), 'Member list grid not found.');
        $this->assertStringContainsString('fi-grid', $list[0]);
        $this->assertSame(4, substr_count($html, 'data-workload-user="'));

        $this->assertSame(4, preg_match_all('/<dl\b[^>]*data-workload-metrics[^>]*>/s', $html, $metricGrids));
        foreach ($metricGrids[0] as $tag) {
            $this->assertStringContainsString('lg:fi-grid-cols', $tag);
            $this->assertStringContainsString('--cols-default: repeat(2, minmax(0, 1fr))', $tag);
            $this->assertStringContainsString('--cols-sm: repeat(3, minmax(0, 1fr))', $tag);
            $this->assertStringContainsString('--cols-lg: repeat(6, minmax(0, 1fr))', $tag);
        }
        $this->assertSame(4 * 6, substr_count($html, 'data-workload-metric="'));
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('overflow-x-auto', $html);

        // Overdue keeps the dashboard's semantic danger colour; other metrics stay neutral.
        $this->assertSame(1, preg_match('/data-workload-user="'.$this->eli->id.'".*?fi-color-danger[^>]*data-workload-metric="overdue">2</s', $html));
        $this->assertSame(0, preg_match('/fi-color-danger[^>]*data-workload-metric="open"/', $html));
    }

    public function test_team_workload_shows_an_empty_state_when_there_are_no_rows(): void
    {
        $this->actingAs($this->admin);
        $this->mock(TeamWorkloadService::class)->shouldReceive('rows')->once()->andReturn(new Collection);

        $this->get(TeamWorkload::getUrl())->assertOk()
            ->assertSee('No active team workload to display.')
            ->assertSee('data-team-workload-empty', false)
            ->assertSee('data-workload-summary="members" data-value="0"', false)
            ->assertDontSee('data-workload-user=', false);
    }

    public function test_team_workload_exposes_no_scoring(): void
    {
        $row = $this->row('Eli Executive');

        foreach (['score', 'rating', 'utilisation', 'utilization', 'rank', 'productivity'] as $word) {
            $this->assertFalse(property_exists($row, $word) || method_exists($row, $word));
        }

        $source = File::get(app_path('Services/Dashboard/TeamWorkloadService.php')).File::get(resource_path('views/filament/pages/team-workload.blade.php'));
        $this->assertStringNotContainsStringIgnoringCase('utilization', $source);
        $this->assertStringNotContainsStringIgnoringCase('productivity', $source);
        $this->assertStringNotContainsStringIgnoringCase('performance score', $source);
    }
}
