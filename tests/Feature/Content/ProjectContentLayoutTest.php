<?php

namespace Tests\Feature\Content;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectContent;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Presentation of the redesigned Project → Content screen. Values come
 * from TargetProgressService and the records; only their placement and
 * wording are asserted.
 */
class ProjectContentLayoutTest extends TestCase
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
        $this->executive = User::factory()->seoExecutive()->create(['name' => 'Emma Executive']);
        $package = Package::factory()->withTargets([
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 4],
        ])->create();
        $client = Client::factory()->create(['name' => 'BrightNest Interiors']);
        $this->project = Project::factory()->forClient($client)->withPackage($package)->ownedBy($this->executive)->create([
            'name' => 'BrightNest Manchester SEO', 'website_url' => 'https://www.example.com', 'target_location' => 'Manchester, UK',
        ]);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    protected function url(Project $project, array $extra = []): string
    {
        return ProjectResource::getUrl('content', ['record' => $project] + $extra);
    }

    protected function page()
    {
        return Livewire::test(ProjectContent::class, ['record' => $this->project->getRouteKey()]);
    }

    public function test_the_workspace_header_module_title_and_primary_action_render(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<h1[^>]*>\s*BrightNest Manchester SEO\s*<\/h1>/s', $html));
        $this->assertStringContainsString('BrightNest Interiors • Manchester, UK', $html);
        $this->assertSame(1, preg_match('/aria-current="page"[^>]*data-project-module="content"|data-project-module="content"[^>]*aria-current="page"/', $html), 'Content is the active module');
        $this->assertSame(1, preg_match('/data-content-header>.*?<h2[^>]*>Content<\/h2>.*?Plan, manage and track SEO content for this project\./s', $html));
        $this->assertStringNotContainsString('Back to project', $html);
        $this->assertStringContainsString('Published blogs assigned to this reporting month count towards the monthly Blog target.', $html);
        $this->assertStringNotContainsString("month's snapshot", $html);

        $this->page()
            ->assertActionVisible('createContent')
            ->assertActionDoesNotExist('viewProject')
            ->assertActionDoesNotExist('delete');
    }

    public function test_monthly_progress_renders_the_blog_card_bar_status_and_compact_selector(): void
    {
        ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        ContentItem::factory()->count(2)->forCycle($this->september)->type(ContentType::Blog)->status(ContentStatus::Writing)->create();
        ContentItem::factory()->forCycle($this->september)->type(ContentType::LandingPage)->published()->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('data-content-view="September 2026"', $html);
        $this->assertStringContainsString('data-content-cycle-status="open"', $html);
        $this->assertSame(1, preg_match('/data-content-card="blogs".*?data-blogs-actual="1" data-blogs-target="4">1 \/ 4<.*?data-blogs-percentage="25">25%<.*?aria-valuenow="25".*?width: 25%.*?data-blogs-remaining="3">3 remaining</s', $html));
        $this->assertSame(1, preg_match('/<label for="content-view"[^>]*>View<\/label>\s*<div style="min-width: 12rem">/s', $html), 'the view selector is compact');
        $this->assertStringContainsString('<option value="unscheduled">Unscheduled</option>', $html);
        $this->assertStringContainsString('<option value="all">All content</option>', $html);
        $this->assertStringNotContainsString('data-content-locked', $html);

        // Workflow summary: existing statuses only, from the same scoped records.
        $this->assertSame(1, preg_match('/data-content-status="writing".*?data-content-status-count="2"/s', $html));
        $this->assertSame(1, preg_match('/data-content-status="published".*?data-content-status-count="2"/s', $html));
        $this->assertSame(1, preg_match('/data-content-status="idea".*?data-content-status-count="0"/s', $html));
        $this->assertSame(1, preg_match('/data-content-summary>4 items · 2 published</', $html));
        $this->assertSame(1, preg_match('/data-content-table-heading>Content in September 2026</', $html));
    }

    public function test_over_target_and_no_target_states_render_without_capping_text_or_inventing_zero(): void
    {
        ContentItem::factory()->count(6)->forCycle($this->september)->type(ContentType::Blog)->published()->create();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-blogs-actual="6" data-blogs-target="4">6 \/ 4<.*?data-blogs-percentage="150">150%<.*?aria-valuenow="100".*?width: 100%.*?data-blogs-remaining="0">2 over target</s', $html));
        $this->assertSame(1, preg_match('/data-content-card="blogs".*?fi-color-success/s', $html), 'a met target reads as success');

        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        ContentItem::factory()->count(3)->forCycle($cycle)->type(ContentType::Blog)->published()->create();

        $html = $this->get($this->url($bare))->assertOk()->getContent();
        $this->assertStringContainsString('data-blogs-actual="3" data-blogs-target="">3 / No target<', $html);
        $this->assertStringContainsString('No blog target was configured for this reporting month.', $html);
        $this->assertStringNotContainsString('data-blogs-percentage', $html);
        $this->assertStringNotContainsString('role="progressbar"', $html, 'no bar, so no invented 0%');
        $this->assertSame(0, preg_match('/>\s*0%\s*</', $html));
    }

    public function test_unscheduled_and_all_views_replace_target_progress_with_clear_headings(): void
    {
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->published()->create(['title' => 'September post']);
        ContentItem::factory()->forProject($this->project)->create(['title' => 'Evergreen idea', 'status' => ContentStatus::Idea]);
        ContentItem::factory()->forCycle($october)->create(['title' => 'October plan', 'status' => ContentStatus::Planned]);

        $this->actingAs($this->manager);

        $html = $this->get($this->url($this->project, ['view' => 'unscheduled']))->assertOk()->getContent();
        $this->assertStringContainsString('data-content-view="unscheduled"', $html);
        $this->assertStringContainsString('Content ideas and work that have not yet been assigned to a reporting month.', $html);
        $this->assertStringNotContainsString('data-content-card="blogs"', $html);
        $this->assertSame(1, preg_match('/data-content-table-heading>Unscheduled content</', $html));
        $this->assertSame(1, preg_match('/data-content-status="idea".*?data-content-status-count="1"/s', $html));
        $this->assertStringContainsString('Evergreen idea', $html);
        $this->assertStringNotContainsString('September post', $html);

        $html = $this->get($this->url($this->project, ['view' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('Select a reporting month to view Blogs Published against its target.', $html);
        $this->assertStringNotContainsString('data-content-card="blogs"', $html);
        $this->assertSame(1, preg_match('/data-content-table-heading>All content</', $html));
        $this->assertSame(1, preg_match('/data-content-summary>3 items · 1 published</', $html));
        $this->assertStringContainsString('October 2026', $html, 'each row shows its month in the all view');
        $this->assertStringContainsString('Unscheduled', $html);
    }

    public function test_the_table_groups_title_keyword_owner_dates_and_badges_compactly(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create(['keyword' => 'interior designers manchester']);
        $published = ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->targeting($keyword)->published('2026-09-18 09:00:00', 'https://www.example.com/blog/interior-design-trends')->create([
            'title' => '10 Interior Design Trends for Manchester Homes', 'planned_publish_date' => '2026-09-12',
        ]);
        $writing = ContentItem::factory()->forCycle($this->september)->type(ContentType::ServicePage)->assignedTo($this->executive)->status(ContentStatus::Writing)->create([
            'title' => 'How to Choose a Designer', 'planned_publish_date' => '2026-09-22', 'target_keyword_id' => null,
        ]);

        $this->actingAs($this->manager);

        $component = $this->page()
            ->assertCanSeeTableRecords([$published, $writing])
            ->assertSee('10 Interior Design Trends for Manchester Homes')
            ->assertSee('Target: interior designers manchester')
            ->assertSee('No target keyword')
            ->assertSee('Emma Executive')
            ->assertSee('Unassigned')
            ->assertSee('Blog')
            ->assertSee('Service page')
            ->assertSee('Planned: 12 Sep 2026')
            ->assertSee('Published: 18 Sep 2026')
            ->assertSee('Planned: 22 Sep 2026')
            ->assertSee('Writing')
            ->assertSee('Published')
            ->assertSee('Search title, keyword or URL')
            ->assertTableActionVisible('edit', $writing)
            ->assertTableActionVisible('publish', $writing)
            ->assertTableActionVisible('advance', $writing)
            ->assertTableActionVisible('setStatus', $writing)
            ->assertTableActionHidden('publish', $published)
            ->assertTableActionDoesNotExist('delete', record: $published);

        $html = $component->html();
        $this->assertStringNotContainsString('September 2026 · ', $html, 'the month is not repeated under titles in a month view');
        foreach (['Target keyword', 'Assignee', 'Published URL'] as $wideColumn) {
            $this->assertSame(0, preg_match('/<th[^>]*>\s*(?:<[^>]+>\s*)*'.preg_quote($wideColumn, '/').'\s*</', $html), $wideColumn.' is no longer its own column');
        }
    }

    public function test_the_empty_states_are_compact_and_offer_the_existing_add_workflow(): void
    {
        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('No content planned yet', $html);
        $this->assertStringContainsString('Add blogs, landing pages and other SEO content to track their progress through the month.', $html);
        $this->assertStringNotContainsString('data-content-summary', $html);
        $this->assertStringContainsString('data-content-workflow-empty', $html);
        $this->assertStringContainsString('No content in this view yet.', $html);

        $this->get($this->url($this->project, ['view' => 'unscheduled']))->assertOk()
            ->assertSee('No unscheduled content')
            ->assertSee('Content not assigned to a reporting month will appear here.');

        // The empty-state button is the SAME Add content workflow (ContentItemForm + CreateContentItemAction).
        $this->page()
            ->callTableAction('createFirstContent', data: ['title' => 'First post', 'content_type' => 'blog', 'status' => 'planned', 'monthly_cycle_id' => $this->september->id])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Content added');

        $this->assertSame(1, ContentItem::query()->where('title', 'First post')->count());
    }

    public function test_locked_months_are_flagged_and_read_only(): void
    {
        $frozen = ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->published()->create(['title' => 'Frozen post']);
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs($this->manager);
        $html = $this->get($this->url($this->project))->assertOk()->getContent();

        $this->assertStringContainsString('data-content-cycle-status="locked"', $html);
        $this->assertStringContainsString('This reporting month is locked. Content assigned to this month is read-only.', $html);
        $this->assertStringContainsString('September 2026 (locked)', $html);

        $this->page()
            ->assertTableActionHidden('edit', $frozen)
            ->assertTableActionHidden('setStatus', $frozen)
            ->mountTableAction('setStatus', $frozen)
            ->callMountedTableAction();

        $this->assertSame('Frozen post', $frozen->fresh()->title);
        $this->assertTrue($frozen->fresh()->isPublished());
    }

    public function test_visibility_and_navigation_scope_are_unchanged(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);
        $this->get($this->url($this->project))->assertNotFound();

        $this->actingAs($this->executive);
        $this->get($this->url($this->project))->assertOk()->assertSee('data-project-module="content"', false);

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();
        $this->assertNotContains('Content', $labels, 'Content is never a global navigation item');
    }
}
