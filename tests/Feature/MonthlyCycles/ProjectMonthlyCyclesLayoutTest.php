<?php

namespace Tests\Feature\MonthlyCycles;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectMonthlyCycles;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned Project → Monthly cycles screen. Status,
 * dates and targets come from the stored cycle rows; only their placement
 * and wording are asserted.
 */
class ProjectMonthlyCyclesLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $executive;

    protected Package $package;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create(['name' => 'James Manager']);
        $this->executive = User::factory()->seoExecutive()->create();
        $this->package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 20],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 4],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4],
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 6],
        ])->create();
        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->withPackage($this->package)->ownedBy($this->executive)->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.example.com', 'target_location' => 'Manchester, UK',
        ]);
    }

    protected function url(Project $project): string
    {
        return ProjectResource::getUrl('monthly-cycles', ['record' => $project]);
    }

    protected function page(?Project $project = null)
    {
        return Livewire::test(ProjectMonthlyCycles::class, ['record' => ($project ?? $this->project)->getRouteKey()]);
    }

    public function test_the_workspace_header_title_selector_and_selected_month_render(): void
    {
        $cycle = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html));
        $this->assertStringContainsString('BrightNest Interiors • Manchester, UK', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="monthly-cycles"|data-project-module="monthly-cycles"[^>]*aria-current="page"/', $html), 'Monthly cycles is the active module');
        $this->assertSame(1, preg_match('/data-cycles-header>.*?<h2[^>]*>Monthly cycles<\/h2>.*?View each reporting month and the targets that were set for it\./s', $html));
        $this->assertStringNotContainsString('Back to project', $html);
        $this->assertStringNotContainsString('snapshot', strtolower(strip_tags(preg_replace('/<script.*?<\/script>/s', '', $html))), 'no developer wording in visible text');

        $this->assertSame(1, preg_match('/<label for="cycles-month"[^>]*>Reporting month<\/label>\s*<div style="min-width: 12rem">/s', $html), 'the month selector is compact');
        $this->assertSame(1, preg_match('/data-cycle-heading>\s*September 2026.*?data-cycle-status="open".*?Open.*?data-cycle-current/s', $html), 'the current month is flagged');
        $this->assertStringContainsString('Monthly work can be added for this reporting month.', $html);
        $this->assertStringNotContainsString('data-cycles-missing-current', $html);

        // Summary cards.
        $this->assertSame(1, preg_match('/<div\b[^>]*data-cycle-summary[^>]*>/s', $html, $summary));
        $this->assertStringContainsString('--cols-sm: repeat(2', $summary[0]);
        $this->assertStringContainsString('--cols-xl: repeat(4', $summary[0]);
        $this->assertSame(1, preg_match('/data-cycle-card="status".*?data-cycle-card-status="open">Open</s', $html));
        $this->assertSame(1, preg_match('/data-cycle-card="started".*?data-cycle-started="'.$cycle->started_at->toDateString().'">'.$cycle->started_at->format('j M Y').'</s', $html));
        $this->assertSame(1, preg_match('/data-cycle-card="lock".*?data-cycle-locked="0">Not locked</s', $html));
        $this->assertSame(1, preg_match('/data-cycle-card="report".*?data-cycle-report="none">Not started</s', $html));
        $this->assertStringNotContainsString('data-cycle-locked-note', $html);

        $this->page()->assertSet('selectedCycleId', $cycle->id)->assertActionHidden('ensureCurrentMonth');
    }

    public function test_targets_render_as_tiles_from_the_stored_values(): void
    {
        app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 777],
        ]);

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div\b[^>]*data-cycle-targets[^>]*>/s', $html, $targets));
        $this->assertStringContainsString('--cols-lg: repeat(4', $targets[0]);
        $this->assertStringContainsString('data-cycle-target="backlinks"', $html);
        $this->assertStringContainsString('data-target-key="backlinks">20<', $html);
        $this->assertStringContainsString('data-target-key="guest_posts">4<', $html);
        $this->assertStringContainsString('data-target-key="pages_optimized">6<', $html);
        $this->assertStringNotContainsString('data-target-key="backlinks">777<', $html, 'the stored targets never follow later package changes');
        $this->assertStringContainsString('These are the targets set for September 2026. Future package changes will not change them.', $html);
        $this->assertStringNotContainsString('<table', substr($html, strpos($html, 'data-cycle-targets')), 'no raw target table');
        $this->assertStringNotContainsString('data-cycle-no-targets', $html);

        // No package: a calm empty state, never zeros.
        $bare = Project::factory()->ownedBy($this->executive)->create();
        app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        $html = $this->get($this->url($bare))->assertOk()->getContent();
        $this->assertStringContainsString('No monthly targets were set for this period.', $html);
        $this->assertStringNotContainsString('data-target-key', $html);
    }

    public function test_reporting_and_locked_states_render_calmly_with_the_locker_and_report(): void
    {
        $cycle = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $report = app(EnsureMonthlyReportAction::class)->handle($cycle);
        $cycle->forceFill(['status' => MonthlyCycleStatus::Reporting])->save();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();
        $this->assertStringContainsString('data-cycle-status="reporting"', $html);
        $this->assertStringContainsString('The report for this month is being prepared.', $html);
        $this->assertSame(1, preg_match('/data-cycle-card="report".*?data-cycle-report="draft">Draft<.*?v1/s', $html));
        $this->assertStringContainsString('data-cycle-work-link="report"', $html);
        $this->assertStringContainsString('href="'.ProjectResource::getUrl('report', ['record' => $this->project, 'report' => $report]).'"', $html);

        $cycle->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => Carbon::parse('2026-09-10 14:30:00'), 'locked_by' => $this->manager->id])->save();
        $html = $this->get($this->url($this->project))->assertOk()->getContent();
        $this->assertStringContainsString('data-cycle-status="locked"', $html);
        $this->assertSame(1, preg_match('/data-cycle-card="lock".*?data-cycle-locked="1">Locked<.*?Locked on 10 Sep 2026 by James Manager/s', $html));
        $this->assertStringContainsString('This reporting month is locked. Monthly work is read-only.', $html);
        $this->assertStringContainsString('September 2026 (locked)', $html);
        $this->assertStringNotContainsString('fi-color-danger', substr($html, strpos($html, 'data-cycle-summary')), 'a locked month is not presented as an error');
    }

    public function test_work_links_selector_and_recent_strip_switch_months(): void
    {
        $august = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 8));
        $september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-cycle-work>.*?data-cycle-work-link="tasks".*?data-cycle-work-link="monthly-work".*?data-cycle-work-link="reports"/s', $html));
        $this->assertStringContainsString('href="'.ProjectResource::getUrl('tasks', ['record' => $this->project, 'cycle' => $september->getKey()]).'"', $html);
        $this->assertStringContainsString('href="'.ProjectResource::getUrl('monthly-work', ['record' => $this->project, 'cycle' => $september->getKey()]).'"', $html);
        $this->assertStringContainsString('View tasks', $html);
        $this->assertStringContainsString('Work for September', $html);
        $this->assertSame(1, preg_match('/data-cycles-strip>.*?data-cycles-strip-item="'.$september->getKey().'"[^>]*aria-current="true".*?Sep 2026 · Open.*?data-cycles-strip-item="'.$august->getKey().'".*?Aug 2026 · Open/s', $html));

        $this->page()
            ->assertSet('selectedCycleId', $september->id)
            ->set('selectedCycleId', $august->id)
            ->assertSee('Work for August')
            ->assertSee('href="'.ProjectResource::getUrl('tasks', ['record' => $this->project, 'cycle' => $august->getKey()]).'"', false)
            ->assertDontSee('data-cycle-current', false);
    }

    public function test_missing_current_month_offers_the_existing_ensure_action_only_to_authorised_users(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-cycles-missing-current="September 2026">.*?No monthly cycle for September 2026.*?No monthly cycles yet\..*?This month has not been started for this project yet\..*?data-cycles-ensure/s', $html));
        $this->assertStringContainsString("mountAction('ensureCurrentMonth')", $html);
        $this->assertStringNotContainsString('data-cycle-summary', $html);
        $this->assertSame(0, $this->project->monthlyCycles()->count(), 'rendering never creates a cycle');

        $this->page()
            ->assertActionVisible('ensureCurrentMonth')
            ->callAction('ensureCurrentMonth')
            ->assertNotified('September 2026 cycle ready')
            ->assertActionHidden('ensureCurrentMonth')
            ->assertSee('data-cycle-status="open"', false)
            ->assertSee('data-target-key="backlinks">20<', false);

        $this->assertSame(1, $this->project->monthlyCycles()->count());

        // An executive sees the missing-month notice without the button, and cannot create the cycle.
        $other = Project::factory()->withPackage($this->package)->ownedBy($this->executive)->create();
        $this->actingAs($this->executive);
        $html = $this->get($this->url($other))->assertOk()->getContent();
        $this->assertStringContainsString('No monthly cycle for September 2026', $html);
        $this->assertStringNotContainsString('data-cycles-ensure', $html);
        $this->page($other)->assertActionHidden('ensureCurrentMonth')->mountAction('ensureCurrentMonth')->callMountedAction();
        $this->assertSame(0, $other->monthlyCycles()->count());
    }

    public function test_visibility_is_unchanged(): void
    {
        app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get($this->url($this->project))->assertNotFound();

        $this->actingAs($this->executive);
        $this->get($this->url($this->project))->assertOk()->assertSee('data-project-module="monthly-cycles"', false)->assertSee('data-target-key="blogs">4<', false);
    }
}
