<?php

namespace Tests\Feature\Projects;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Projects\SyncProjectTargetOverridesAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Backlink;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned project overview. Values come from the
 * existing services; these tests only check that they are rendered where
 * the layout says, with the responsive grid markers, and that project
 * visibility is untouched.
 */
class ProjectOverviewLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $owner;

    protected User $member;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create(['name' => 'James Manager']);
        $this->owner = User::factory()->seoExecutive()->create(['name' => 'Emma Executive']);
        $this->member = User::factory()->seoExecutive()->create(['name' => 'Noah Executive']);

        $package = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 20],
            ['target_key' => 'guest_posts', 'label' => 'Guest posts', 'target_value' => 4],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4],
        ])->create(['name' => 'Growth SEO']);

        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->withPackage($package)->ownedBy($this->owner)->withTeam([$this->member])->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.brightnest.example/', 'target_location' => 'Manchester, UK', 'start_date' => '2026-09-01',
        ]);
        app(SyncProjectTargetOverridesAction::class)->handle($this->project, ['guest_posts' => 2]);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        Backlink::factory()->count(3)->forCycle($this->september)->status(BacklinkStatus::Live)->create(['type' => BacklinkType::GuestPost]);
        Backlink::factory()->count(9)->forCycle($this->september)->status(BacklinkStatus::Live)->create(['type' => BacklinkType::Citation]);
    }

    public function test_the_header_shows_the_project_name_client_location_status_and_website_instead_of_view(): void
    {
        $this->actingAs($this->manager);

        $response = $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html), 'the project name is the page heading');
        $this->assertSame(0, preg_match('/<h1[^>]*>\s*View\s*<\/h1>/s', $html));
        $response->assertSee('BrightNest Interiors • Manchester, UK')
            ->assertSee('data-project-status="active"', false)
            ->assertSee('brightnest.example')
            ->assertSee('href="https://www.brightnest.example/"', false)
            ->assertSee('Package: Growth SEO');
    }

    public function test_module_navigation_wraps_in_a_responsive_grid_and_keeps_every_module_link(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->getContent();

        $this->assertSame(1, preg_match('/<nav\b[^>]*data-project-modules[^>]*>/s', $html, $nav));
        foreach (['fi-grid', 'sm:fi-grid-cols', 'lg:fi-grid-cols', 'xl:fi-grid-cols', '--cols-default: repeat(2', '--cols-sm: repeat(3', '--cols-lg: repeat(5', '--cols-xl: repeat(6'] as $marker) {
            $this->assertStringContainsString($marker, $nav[0]);
        }

        foreach (['tasks', 'pages', 'keywords', 'backlinks', 'content', 'analytics', 'monthly-work', 'reports', 'report-sections', 'monthly-cycles'] as $page) {
            $this->assertStringContainsString('href="'.ProjectResource::getUrl($page, ['record' => $this->project]).'"', $html, $page);
            $this->assertStringContainsString('data-project-module="'.$page.'"', $html);
        }

        $this->assertStringContainsString('data-project-module="overview"', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="overview"|data-project-module="overview"[^>]*aria-current="page"/', $html), 'the overview module is highlighted as current');

        // Administrative actions stay in the page header, not in the module grid.
        Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
            ->assertActionVisible('edit')
            ->assertActionVisible('archive')
            ->assertActionDoesNotExist('tasks')
            ->assertActionDoesNotExist('keywords');
    }

    public function test_executives_do_not_see_the_report_sections_module_they_cannot_manage(): void
    {
        $this->actingAs($this->owner);
        $html = $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-project-module="report-sections"', $html);
        $this->assertStringContainsString('data-project-module="reports"', $html);
    }

    public function test_operations_render_the_period_badge_metric_cards_and_deliverable_progress(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertOk()->getContent();

        $this->assertStringContainsString('data-operations-period="September 2026"', $html);
        $this->assertStringContainsString('data-operations-cycle-status="open"', $html);

        $this->assertSame(1, preg_match('/<div\b[^>]*data-project-operations[^>]*>/s', $html, $cards));
        $this->assertStringContainsString('--cols-default: repeat(2', $cards[0]);
        $this->assertStringContainsString('--cols-lg: repeat(4', $cards[0]);

        foreach (['completion', 'open_tasks', 'overdue_tasks', 'report'] as $card) {
            $this->assertStringContainsString('data-operations-card="'.$card.'"', $html);
        }

        // Backlinks 12/20 = 60%, guest posts 3/2 = 150% (over target), blogs 0/4, pages: no target set.
        $this->assertSame(1, preg_match('/data-deliverable="backlinks".*?data-operations-backlinks>12 \/ 20<.*?data-deliverable-percentage="60">60%<.*?8 remaining/s', $html));
        $this->assertSame(1, preg_match('/data-deliverable="guest_posts".*?>3 \/ 2<.*?data-deliverable-percentage="150">150%<.*?1 over target/s', $html));
        $this->assertSame(1, preg_match('/data-deliverable="guest_posts".*?aria-valuenow="100".*?width: 100%/s', $html), 'the bar stops at 100% while the text says 150%');
        $this->assertSame(1, preg_match('/data-deliverable="blogs".*?data-operations-blogs>0 \/ 4<.*?data-deliverable-percentage="0">0%<.*?4 remaining/s', $html));
        $this->assertSame(1, preg_match('/data-deliverable="pages_optimized".*?data-operations-pages>0 \/ No target<.*?No target set for this month/s', $html));
        $this->assertStringNotContainsString('data-deliverable="pages_optimized" data-operations-target="pages_optimized" data-percentage="0"', $html, 'no invented 0% for a missing target');

        // The aggregate keeps its capped rule: (60 + 100 + 0) / 3 = 53.
        $this->assertStringContainsString('data-operations-completion="53"', $html);
        $this->assertStringContainsString('1 of 3 targets met', $html);
    }

    public function test_details_package_and_team_sections_are_easy_to_scan(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<dl\b[^>]*data-project-details[^>]*>/s', $html, $details));
        $this->assertStringContainsString('--cols-sm: repeat(2', $details[0]);
        foreach (['Project name', 'Status', 'Client', 'Website', 'Target location', 'Start date', 'End date', '1 Sep 2026'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->assertStringContainsString('data-project-package="'.$this->project->package_id.'"', $html);
        $this->assertStringContainsString('Growth SEO', $html);
        $this->assertSame(1, preg_match('/data-project-target="guest_posts" data-resolved="2".*?Project override.*?Package default 4/s', $html));
        $this->assertSame(1, preg_match('/data-project-target="backlinks" data-resolved="20"/', $html));
        $this->assertStringContainsString('Target changes affect future monthly cycles.', $html);
        $this->assertStringNotContainsString('Resolved monthly target', $html, 'no technical wording');

        $this->assertSame(1, preg_match('/data-team-primary="'.$this->owner->id.'".*?Emma Executive.*?Primary.*?SEO Executive/s', $html));
        $this->assertSame(1, preg_match('/data-team-member="'.$this->member->id.'".*?Noah Executive.*?SEO Executive/s', $html));
        $this->assertStringNotContainsString('data-team-empty', $html);

        // Two-column overview grids on wide screens, single column otherwise.
        $this->assertGreaterThanOrEqual(2, substr_count($html, '--cols-xl: repeat(2, minmax(0, 1fr))'));
    }

    public function test_an_unassigned_project_without_cycle_or_package_renders_its_empty_states(): void
    {
        $bare = Project::factory()->create(['name' => 'Bare Site']);
        $this->actingAs($this->manager);

        $this->get(ProjectResource::getUrl('view', ['record' => $bare]))->assertOk()
            ->assertSee('No monthly cycle for September 2026.')
            ->assertSee('data-operations-ensure-cycle', false)
            ->assertSee('No package assigned.')
            ->assertSee('data-team-primary="none"', false)
            ->assertSee('No additional team members assigned.')
            ->assertSee('Available once the monthly cycle exists.');

        $this->assertSame(0, $bare->monthlyCycles()->count(), 'rendering never creates a cycle');
    }

    public function test_project_visibility_is_unchanged(): void
    {
        $outsider = User::factory()->seoExecutive()->create();

        $this->actingAs($outsider);
        $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertNotFound();

        $this->actingAs($this->member);
        $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))->assertOk()->assertSee('BrightNest Manchester SEO');
    }
}
