<?php

namespace Tests\Feature\Dashboard;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportRevision;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Dashboard\DashboardOverviewService;
use App\Support\Dashboard\AttentionItem;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $manager;

    protected User $executive;

    protected User $outsider;

    protected Project $alpha;

    protected Project $beta;

    protected Project $gamma;

    protected Project $delta;

    protected MonthlyCycle $alphaCycle;

    protected MonthlyCycle $gammaCycle;

    protected function setUp(): void
    {
        parent::setUp();

        // A Tuesday; the calendar week runs Mon 14 – Sun 20 September.
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->admin = User::factory()->superAdmin()->create();
        $this->manager = User::factory()->seoManager()->create();
        $this->executive = User::factory()->seoExecutive()->create(['name' => 'Eli Executive']);
        $this->outsider = User::factory()->seoExecutive()->create();

        // Alpha: owned by Eli, September cycle, incomplete draft, tasks in every state.
        $this->alpha = Project::factory()->ownedBy($this->executive)->forClient(Client::factory()->create(['name' => 'Alpha Client']))->create(['name' => 'Alpha']);
        $this->alphaCycle = app(CreateMonthlyCycleAction::class)->handle($this->alpha, new CyclePeriod(2026, 9));
        app(EnsureMonthlyReportAction::class)->handle($this->alphaCycle);
        Task::factory()->forCycle($this->alphaCycle)->assignedTo($this->executive)->due('2026-09-10')->create(['title' => 'Overdue alpha']);
        Task::factory()->forCycle($this->alphaCycle)->assignedTo($this->executive)->due('2026-09-15')->create(['title' => 'Today alpha']);
        Task::factory()->forCycle($this->alphaCycle)->assignedTo($this->executive)->due('2026-09-18')->create(['title' => 'This week alpha']);
        Task::factory()->forProject($this->alpha)->assignedTo($this->executive)->create(['title' => 'Undated alpha']);
        Task::factory()->forCycle($this->alphaCycle)->status(TaskStatus::Completed)->due('2026-09-01')->create(['title' => 'Done alpha']);
        Task::factory()->forCycle($this->alphaCycle)->status(TaskStatus::Cancelled)->due('2026-09-01')->create(['title' => 'Cancelled alpha']);
        Task::factory()->forCycle($this->alphaCycle)->due('2026-09-01')->create(['title' => 'Deleted alpha'])->delete();

        // Beta: active, NO September cycle (missing), one overdue task — Eli cannot see it.
        $this->beta = Project::factory()->forClient(Client::factory()->create(['name' => 'Beta Client']))->create(['name' => 'Beta']);
        Task::factory()->forProject($this->beta)->due('2026-09-01')->create(['title' => 'Overdue beta']);

        // Gamma: active, September cycle in reporting, report Ready for Review (v2 correction).
        $this->gamma = Project::factory()->forClient(Client::factory()->create(['name' => 'Gamma Client']))->create(['name' => 'Gamma']);
        $this->gammaCycle = app(CreateMonthlyCycleAction::class)->handle($this->gamma, new CyclePeriod(2026, 9));
        $this->gammaCycle->forceFill(['status' => MonthlyCycleStatus::Reporting])->save();
        $gammaReport = app(EnsureMonthlyReportAction::class)->handle($this->gammaCycle);
        $gammaReport->sections()->update(['is_required' => false]);
        $gammaReport->forceFill(['status' => ReportStatus::ReadyForReview, 'version' => 2])->save();
        MonthlyReportRevision::factory()->forReport($gammaReport, 1)->create();

        // Delta: final report, locked month.
        $this->delta = Project::factory()->forClient(Client::factory()->create(['name' => 'Delta Client']))->create(['name' => 'Delta']);
        $deltaCycle = app(CreateMonthlyCycleAction::class)->handle($this->delta, new CyclePeriod(2026, 9));
        app(EnsureMonthlyReportAction::class)->handle($deltaCycle)->forceFill(['status' => ReportStatus::Final, 'snapshot_json' => ['schema_version' => 1], 'finalized_at' => now(), 'finalized_by' => $this->manager->id])->save();
        $deltaCycle->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        // Ignored: a paused project and an archived project, both with overdue tasks and no cycle.
        Task::factory()->forProject(Project::factory()->paused()->create(['name' => 'Paused']))->due('2026-09-01')->create();
        $archived = Project::factory()->create(['name' => 'Archived']);
        Task::factory()->forProject($archived)->due('2026-09-01')->create();
        $archived->delete();
    }

    public function test_admin_and_manager_see_organisation_wide_counts(): void
    {
        foreach ([$this->admin, $this->manager] as $user) {
            $overview = app(DashboardOverviewService::class)->for($user);

            $this->assertSame('September 2026', $overview->period->label());
            $this->assertSame(4, $overview->activeProjects);
            $this->assertSame(1, $overview->missingCycles);
            $this->assertSame(5, $overview->openTasks, 'alpha 4 open + beta 1; completed/cancelled/deleted/paused/archived excluded');
            $this->assertSame(2, $overview->overdueTasks, 'alpha overdue + beta overdue');
            $this->assertSame(1, $overview->reportsReadyForReview);
            $this->assertSame(1, $overview->reportsDraft);
            $this->assertSame(1, $overview->reportsFinal);
            $this->assertSame(0, $overview->reportsNotStarted);
            $this->assertSame(['Alpha', 'Beta', 'Delta', 'Gamma'], $overview->rows->map(fn ($r) => $r->project->name)->values()->all());
        }
    }

    public function test_executive_counts_only_accessible_projects_and_an_outsider_sees_nothing(): void
    {
        $overview = app(DashboardOverviewService::class)->for($this->executive);

        $this->assertSame(1, $overview->activeProjects);
        $this->assertSame(0, $overview->missingCycles);
        $this->assertSame(4, $overview->openTasks);
        $this->assertSame(1, $overview->overdueTasks);
        $this->assertSame(0, $overview->reportsReadyForReview);
        $this->assertSame(1, $overview->reportsDraft);
        $this->assertSame(0, $overview->reportsFinal);
        $this->assertSame(['Alpha'], $overview->rows->map(fn ($r) => $r->project->name)->values()->all());
        $this->assertSame(['Alpha'], $overview->attention->map(fn ($i) => $i->project->name)->all());

        $this->assertSame(['open' => 4, 'overdue' => 1, 'due_today' => 1, 'due_this_week' => 2], $overview->myWork);

        $none = app(DashboardOverviewService::class)->for($this->outsider);
        $this->assertSame(0, $none->activeProjects);
        $this->assertSame(0, $none->openTasks);
        $this->assertSame(0, $none->reportsDraft);
        $this->assertTrue($none->rows->isEmpty());
        $this->assertTrue($none->attention->isEmpty());

        $this->actingAs($this->outsider);
        $html = $this->get(Dashboard::getUrl())->assertOk()->getContent();

        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Alpha Client', 'Beta Client', 'Eli Executive'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        $this->assertStringContainsString('data-dashboard-card="active_projects" data-value="0"', $html);
        $this->assertStringContainsString('data-attention-empty', $html);
    }

    public function test_missing_cycles_are_detected_without_creating_them(): void
    {
        $cycles = MonthlyCycle::query()->count();

        $overview = app(DashboardOverviewService::class)->for($this->admin);
        $this->assertSame(1, $overview->missingCycles);
        $this->assertTrue($overview->rows->firstWhere(fn ($r) => $r->project->is($this->beta))->isMissingCycle());

        $this->actingAs($this->admin);
        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('data-dashboard-card="missing_cycles" data-value="1"', false)
            ->assertSee('data-attention-reason="missing_cycle"', false);

        $this->assertSame($cycles, MonthlyCycle::query()->count());
        $this->assertSame(0, $this->beta->monthlyCycles()->count());
        $this->assertSame(4, MonthlyReport::query()->count() + 1 - 1 + 1, 'Reports are untouched by rendering (3 + sanity).');
    }

    public function test_attention_queue_reasons_are_explicit_and_deterministic(): void
    {
        $attention = app(DashboardOverviewService::class)->for($this->admin)->attention;

        // Ordered by most urgent reason, then name: Beta (missing cycle), Gamma (ready), Alpha (incomplete + overdue).
        $this->assertSame(['Beta', 'Gamma', 'Alpha'], $attention->map(fn (AttentionItem $i) => $i->project->name)->all());

        $beta = $attention[0];
        $this->assertSame([AttentionItem::MISSING_CYCLE, AttentionItem::OVERDUE_TASKS], $beta->reasonCodes());
        $this->assertSame('Beta — September 2026', $beta->title());
        $this->assertSame('1 overdue task', $beta->reasons[1]['label']);

        $gamma = $attention[1];
        $this->assertSame([AttentionItem::READY_FOR_REVIEW, AttentionItem::CORRECTION_IN_PROGRESS], $gamma->reasonCodes());
        $this->assertStringContainsString('v2', $gamma->reasons[0]['detail']);

        $alpha = $attention[2];
        $this->assertSame([AttentionItem::REPORT_INCOMPLETE, AttentionItem::OVERDUE_TASKS], $alpha->reasonCodes());
        $this->assertSame('Report readiness 0%', $alpha->reasons[0]['label']);
        $this->assertStringContainsString('Missing: Executive Summary', $alpha->reasons[0]['detail']);

        // Delta (final, no tasks) needs nothing. Same data → same result.
        $this->assertNull($attention->first(fn ($i) => $i->project->is($this->delta)));
        $again = app(DashboardOverviewService::class)->for($this->admin)->attention;
        $this->assertSame($attention->map(fn ($i) => [$i->project->name, $i->reasonCodes()])->all(), $again->map(fn ($i) => [$i->project->name, $i->reasonCodes()])->all());

        // A reporting cycle without a report is flagged too.
        $this->gammaCycle->monthlyReport->revisions()->delete();
        $this->gammaCycle->monthlyReport()->delete();
        $this->assertContains(AttentionItem::REPORT_NOT_STARTED, app(DashboardOverviewService::class)->for($this->admin)->attention->firstWhere(fn ($i) => $i->project->is($this->gamma))->reasonCodes());
    }

    public function test_dashboard_page_renders_cards_attention_my_work_and_review_queue_by_role(): void
    {
        $this->actingAs($this->manager);

        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('data-dashboard-card="active_projects" data-value="4"', false)
            ->assertSee('data-dashboard-card="overdue_tasks" data-value="2"', false)
            ->assertSee('data-dashboard-card="reports_ready" data-value="1"', false)
            ->assertSee('data-dashboard-card="reports_final" data-value="1"', false)
            ->assertSee('Needs attention')
            ->assertSee('data-attention-reasons="missing_cycle,overdue_tasks"', false)
            ->assertSee('data-review-queue', false)
            ->assertSee('Gamma')
            ->assertSee('Team workload');

        Livewire::test(Dashboard::class)
            ->assertCanSeeTableRecords([$this->alpha, $this->beta, $this->gamma, $this->delta])
            ->filterTable('report_status', 'ready_for_review')
            ->assertCanSeeTableRecords([$this->gamma])
            ->assertCanNotSeeTableRecords([$this->alpha, $this->beta])
            ->resetTableFilters()
            ->filterTable('report_status', 'none')
            ->assertCanSeeTableRecords([$this->beta])
            ->assertCanNotSeeTableRecords([$this->alpha, $this->gamma])
            ->resetTableFilters()
            ->searchTable('Gamma Client')
            ->assertCanSeeTableRecords([$this->gamma])
            ->assertCanNotSeeTableRecords([$this->alpha]);

    }

    public function test_executive_dashboard_page_is_scoped_to_their_projects(): void
    {
        $this->actingAs($this->executive);

        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSee('data-dashboard-card="active_projects" data-value="1"', false)
            ->assertSee('data-my-work-overdue="1"', false)
            ->assertSee('data-my-work-today="1"', false)
            ->assertSee('data-my-work-week="2"', false)
            ->assertSee('data-my-work-open="4"', false)
            ->assertDontSee('data-review-queue', false)
            ->assertDontSee('Team workload')
            ->assertDontSee('Gamma')
            ->assertDontSee('Beta Client');

        Livewire::test(Dashboard::class)
            ->assertCanSeeTableRecords([$this->alpha])
            ->assertCanNotSeeTableRecords([$this->beta, $this->gamma, $this->delta])
            ->searchTable('Gamma')
            ->assertCanNotSeeTableRecords([$this->gamma]);
    }

    public function test_dashboard_query_count_does_not_grow_per_project(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(DashboardOverviewService::class)->for($this->admin);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $baseline = $count();

        // Ten more active projects with cycles but no reports (no per-report readiness work).
        foreach (range(1, 10) as $i) {
            $project = Project::factory()->create(['name' => "Extra {$i}"]);
            $cycle = app(CreateMonthlyCycleAction::class)->handle($project, new CyclePeriod(2026, 9));
            Task::factory()->forCycle($cycle)->due('2026-09-01')->create();
        }

        $grown = $count();

        $this->assertLessThanOrEqual($baseline + 2, $grown, "Dashboard queries grew from {$baseline} to {$grown} for 10 more projects.");
    }

    public function test_nothing_out_of_scope_is_introduced(): void
    {
        foreach (['csv_imports', 'analytics_syncs', 'client_portal_users', 'ai_recommendations', 'employee_scores'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        foreach ([app_path('Imports'), app_path('Services/Integrations'), app_path('Services/Scoring')] as $path) {
            $this->assertFalse(File::exists($path));
        }

        foreach (['App\Services\Dashboard\EmployeePerformanceService', 'App\Services\Dashboard\ClientHealthScoreService', 'App\Actions\Reports\BatchFinalizeReportsAction'] as $class) {
            $this->assertFalse(class_exists($class));
        }

        $source = File::get(app_path('Services/Dashboard/TeamWorkloadService.php')).File::get(app_path('Services/Dashboard/DashboardOverviewService.php'));
        foreach (['utilisation', 'utilization', 'productivity', 'ranking', 'score'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word.'(', $source);
        }
    }

    /**
     * The summary cards sit in Filament's responsive grid (1 column, 2 from
     * the sm breakpoint, 4 from xl) using stat-card styles the panel
     * stylesheet ships, so every card is styled and the same height.
     */
    public function test_dashboard_summary_cards_use_the_responsive_filament_grid(): void
    {
        $this->actingAs($this->admin);
        $html = $this->get(Dashboard::getUrl())->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div\b[^>]*data-dashboard-cards[^>]*>/s', $html, $tag), 'Summary card grid not found.');
        $this->assertSame(1, preg_match('/class="([^"]*)"/', $tag[0], $class));
        $this->assertSame(1, preg_match('/style="([^"]*)"/', $tag[0], $style));

        foreach (['fi-grid', 'sm:fi-grid-cols', 'xl:fi-grid-cols'] as $name) {
            $this->assertStringContainsString($name, $class[1]);
        }

        $this->assertStringContainsString('--cols-default: repeat(1, minmax(0, 1fr))', $style[1]);
        $this->assertStringContainsString('--cols-sm: repeat(2, minmax(0, 1fr))', $style[1]);
        $this->assertStringContainsString('--cols-xl: repeat(4, minmax(0, 1fr))', $style[1]);
        $this->assertStringNotContainsString('--cols-md', $style[1]);
        $this->assertStringNotContainsString('--cols-lg', $style[1]);

        foreach (['active_projects', 'missing_cycles', 'open_tasks', 'overdue_tasks', 'reports_ready', 'reports_draft', 'reports_not_started', 'reports_final'] as $key) {
            $this->assertSame(1, preg_match('/<div class="fi-wi-stats-overview-stat">.*?<span class="fi-wi-stats-overview-stat-label">[^<]+<\/span>.*?fi-wi-stats-overview-stat-value[^>]*data-dashboard-card="'.$key.'"/s', $html), "Card {$key} is not a stat card.");
        }

        $this->assertSame(8, substr_count($html, '<div class="fi-wi-stats-overview-stat">'));
        $this->assertSame(8, substr_count($html, 'fi-wi-stats-overview-stat-description'));

        // My work metrics: two per row by default, four in one row from lg.
        $this->assertSame(1, preg_match('/<dl\b[^>]*data-my-work[^>]*>/s', $html, $dl), 'My work grid not found.');
        $this->assertStringContainsString('fi-grid', $dl[0]);
        $this->assertStringContainsString('lg:fi-grid-cols', $dl[0]);
        $this->assertStringContainsString('--cols-default: repeat(2, minmax(0, 1fr))', $dl[0]);
        $this->assertStringContainsString('--cols-lg: repeat(4, minmax(0, 1fr))', $dl[0]);
        $this->assertSame(4, preg_match_all('/<div class="fi-wi-stats-overview-stat" style="[^"]*">\s*<div class="fi-wi-stats-overview-stat-content">\s*<dt class="fi-wi-stats-overview-stat-label">/s', $html));

        // The major blocks are direct children of the page content grid, never nested in an unstyled wrapper.
        $this->assertStringNotContainsString('lg:grid-cols-3', $html);
        $this->assertStringNotContainsString('lg:col-span-2', $html);
    }
}
