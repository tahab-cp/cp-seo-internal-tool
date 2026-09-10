<?php

namespace Tests\Feature\Pages;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectPages;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned Project → Pages screen: workspace header
 * and navigation, compact monthly progress, and the table. Values come
 * from the existing services; only their placement is asserted.
 */
class ProjectPagesLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $executive;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->executive = User::factory()->seoExecutive()->create();
        $package = Package::factory()->withTargets([['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 6]])->create();
        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->withPackage($package)->ownedBy($this->executive)->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.brightnest.example', 'target_location' => 'Manchester, UK',
        ]);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    protected function pagesUrl(Project $project, array $extra = []): string
    {
        return ProjectResource::getUrl('pages', ['record' => $project] + $extra);
    }

    public function test_the_page_carries_the_project_workspace_header_with_pages_active(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->pagesUrl($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html), 'the project name is the heading');
        $this->assertStringContainsString('BrightNest Interiors • Manchester, UK', $html);
        $this->assertStringContainsString('data-project-modules', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="pages"|data-project-module="pages"[^>]*aria-current="page"/', $html), 'Pages is the active module');
        $this->assertStringContainsString('href="'.ProjectResource::getUrl('view', ['record' => $this->project]).'"', $html, 'the Overview module links back');
        $this->assertStringNotContainsString('Back to project', $html);

        foreach (['tasks', 'keywords', 'backlinks', 'content', 'analytics', 'monthly-work', 'reports', 'report-sections', 'monthly-cycles'] as $page) {
            $this->assertStringContainsString('data-project-module="'.$page.'"', $html);
        }

        Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()])
            ->assertActionVisible('createPage')
            ->assertActionDoesNotExist('viewProject');
    }

    public function test_monthly_progress_shows_the_period_status_metrics_and_bar(): void
    {
        $pages = Page::factory()->count(7)->forProject($this->project)->create();
        foreach ($pages->take(4) as $page) {
            PageOptimization::factory()->forPage($page)->forCycle($this->september)->create();
        }
        PageOptimization::factory()->forPage($pages[0])->forCycle($this->september)->create(); // same page twice: still one

        $this->actingAs($this->manager);
        $html = $this->get($this->pagesUrl($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('data-pages-period="September 2026"', $html);
        $this->assertStringContainsString('data-pages-cycle-status="open"', $html);
        $this->assertSame(1, preg_match('/<div\b[^>]*data-pages-metrics[^>]*>/s', $html, $metrics));
        $this->assertStringContainsString('--cols-sm: repeat(3', $metrics[0]);

        $this->assertSame(1, preg_match('/data-pages-card="optimised".*?data-pages-optimised="4" data-pages-target="6">4 \/ 6<.*?67% of target/s', $html));
        $this->assertSame(1, preg_match('/data-pages-card="tracked".*?data-pages-tracked="7">7</s', $html));
        $this->assertSame(1, preg_match('/data-pages-card="remaining".*?data-pages-remaining="2">2<.*?2 remaining/s', $html));
        $this->assertSame(1, preg_match('/data-pages-percentage="67">67%<.*?aria-valuenow="67".*?width: 67%/s', $html));
        $this->assertStringContainsString('7 pages tracked · 4 optimised in September 2026', $html);
        $this->assertStringNotContainsString('data-pages-locked', $html);

        // Over target: text says 133%, the bar stops at 100%.
        foreach ($pages->slice(4) as $page) {
            PageOptimization::factory()->forPage($page)->forCycle($this->september)->create();
        }
        Page::factory()->forProject($this->project)->create()->optimizations()->save(PageOptimization::factory()->forCycle($this->september)->make(['project_id' => $this->project->id]));

        $html = $this->get($this->pagesUrl($this->project))->getContent();
        $this->assertSame(1, preg_match('/data-pages-optimised="8" data-pages-target="6">8 \/ 6<.*?133% of target/s', $html));
        $this->assertSame(1, preg_match('/data-pages-percentage="133">133%<.*?aria-valuenow="100".*?width: 100%/s', $html));
        $this->assertStringContainsString('Target exceeded by 2', $html);
        $this->assertSame(1, preg_match('/data-pages-card="remaining".*?Over target.*?>2</s', $html));
    }

    public function test_no_target_and_locked_states_render_without_inventing_zero_percent(): void
    {
        $bare = Project::factory()->ownedBy($this->executive)->create(['name' => 'Bare Site']);
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        PageOptimization::factory()->forPage(Page::factory()->forProject($bare)->create())->forCycle($cycle)->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->pagesUrl($bare))->assertOk()->getContent();

        $this->assertStringContainsString('data-pages-optimised="1" data-pages-target="">1 / No target<', $html);
        $this->assertStringContainsString('No pages-optimised target was configured for this month.', $html);
        $this->assertStringContainsString('No target this month', $html);
        $this->assertStringNotContainsString('data-pages-percentage', $html);
        $this->assertStringNotContainsString('% of target', $html);
        $this->assertStringNotContainsString('role="progressbar"', $html, 'no bar, so no invented 0%');

        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();
        $html = $this->get($this->pagesUrl($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('data-pages-cycle-status="locked"', $html);
        $this->assertStringContainsString('This reporting month is locked. Historical optimisation records are read-only.', $html);
        $this->assertStringContainsString('September 2026 (locked)', $html);

        // A project without any cycle.
        $this->get($this->pagesUrl(Project::factory()->create()))->assertOk()->assertSee('data-pages-no-cycles', false);
    }

    public function test_the_table_shows_the_title_with_its_path_and_a_polished_empty_state(): void
    {
        $this->actingAs($this->manager);

        $html = $this->get($this->pagesUrl($this->project))->assertOk()->getContent();
        $this->assertStringContainsString('No pages added yet', $html);
        $this->assertStringContainsString('Add the important pages from this website', $html);
        $this->assertStringContainsString('Add first page', $html);

        $titled = Page::factory()->forProject($this->project)->create(['url' => 'https://www.brightnest.example/service/interior-design', 'path' => '/service/interior-design', 'title' => 'Interior Design']);
        $untitled = Page::factory()->forProject($this->project)->create(['url' => 'https://blog.other.example/guest-post', 'path' => '/guest-post', 'title' => null]);
        PageOptimization::factory()->forPage($titled)->forCycle($this->september)->create(['optimized_at' => '2026-09-10 09:00:00']);

        $component = Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()])
            ->assertCanSeeTableRecords([$titled, $untitled])
            ->assertSee('Interior Design')
            ->assertSee('/service/interior-design')
            ->assertSee('blog.other.example/guest-post')
            ->assertSee('10 Sep 2026')
            ->assertSee('Never')
            ->assertDontSee('Add first page')
            ->assertTableActionVisible('view', $titled)
            ->assertTableActionVisible('edit', $titled)
            ->assertTableActionVisible('markRemoved', $titled)
            ->assertTableActionVisible('setStatus', $titled)
            ->assertTableActionVisible('open', $titled)
            ->assertTableActionDoesNotExist('delete', record: $titled);

        $this->assertStringNotContainsString('https://www.brightnest.example/service/interior-design</', $component->html(), 'the full URL is not repeated as a second column');

        // The empty-state button is the SAME Add page form and workflow (PageForm + CreatePageAction).
        Page::query()->delete();
        Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()])
            ->assertSee('Add first page')
            ->callTableAction('createFirstPage', data: ['url' => 'https://www.brightnest.example/about', 'status' => 'active', 'title' => 'About'])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Page added');

        $this->assertSame(1, Page::query()->where('title', 'About')->count());
    }

    public function test_visibility_and_navigation_scope_are_unchanged(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get($this->pagesUrl($this->project))->assertNotFound();

        $this->actingAs($this->executive);
        $this->get($this->pagesUrl($this->project))->assertOk()->assertSee('data-project-module="pages"', false);
        $this->get('/admin')->assertOk()->assertDontSee($this->pagesUrl($this->project), 'Pages is never a global navigation item');
    }
}
