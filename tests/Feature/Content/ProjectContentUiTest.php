<?php

namespace Tests\Feature\Content;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectContent;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectContentUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $package = Package::factory()->withTargets([
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create();
        $this->project = Project::factory()->withPackage($package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        $this->actingAs($this->manager);
    }

    protected function page()
    {
        return Livewire::test(ProjectContent::class, ['record' => $this->project->getRouteKey()]);
    }

    public function test_form_validation_including_dynamic_published_requirements(): void
    {
        $this->page()
            ->callAction('createContent', data: ['title' => '', 'content_type' => null, 'status' => null])
            ->assertHasFormErrors(['title' => 'required', 'content_type' => 'required', 'status' => 'required']);

        // Published needs month, date and URL.
        $this->page()
            ->callAction('createContent', data: ['title' => 'Post', 'content_type' => 'blog', 'status' => 'published', 'monthly_cycle_id' => null, 'published_at' => null, 'published_url' => 'not a url'])
            ->assertHasFormErrors(['monthly_cycle_id' => 'required', 'published_at' => 'required', 'published_url' => 'url']);

        $this->assertDatabaseCount('content_items', 0);

        $keyword = Keyword::factory()->forProject($this->project)->create();

        $this->page()
            ->assertSet('selectedView', (string) $this->september->id)
            ->callAction('createContent', data: [
                'title' => 'SEO pricing guide',
                'content_type' => 'blog',
                'status' => 'published',
                'monthly_cycle_id' => $this->september->id,
                'target_keyword_id' => $keyword->id,
                'assigned_user_id' => $this->manager->id,
                'planned_publish_date' => '2026-09-10',
                'published_at' => '2026-09-12 09:00',
                'published_url' => 'https://site.example/blog/pricing',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Content added');

        $item = ContentItem::query()->where('title', 'SEO pricing guide')->firstOrFail();
        $this->assertTrue($item->targetKeyword->is($keyword));
        $this->assertTrue($item->isPublished());
    }

    public function test_header_progress_and_views(): void
    {
        ContentItem::factory()->count(6)->forCycle($this->september)->type(ContentType::Blog)->published()->create();
        ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->status(ContentStatus::Approved)->create(['title' => 'Approved only']);
        $unscheduled = ContentItem::factory()->forProject($this->project)->create(['title' => 'Evergreen idea']);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $octoberItem = ContentItem::factory()->forCycle($october)->type(ContentType::Blog)->published()->create(['title' => 'October post']);

        $this->get(ProjectResource::getUrl('content', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('data-blogs-actual="6"', false)
            ->assertSee('data-blogs-target="8"', false)
            ->assertSee('6 / 8')
            ->assertSee('2 remaining');

        $page = $this->page();

        $page->assertSet('selectedView', (string) $this->september->id)
            ->assertCanNotSeeTableRecords([$unscheduled, $octoberItem])
            ->set('selectedView', 'unscheduled')
            ->assertCanSeeTableRecords([$unscheduled])
            ->assertCanNotSeeTableRecords([$octoberItem])
            ->assertSee('Unscheduled view')
            ->set('selectedView', 'all')
            ->assertCanSeeTableRecords([$unscheduled, $octoberItem])
            ->set('selectedView', (string) $october->id)
            ->assertCanSeeTableRecords([$octoberItem])
            ->assertSee('1 / 8')
            ->assertSee('7 remaining');

        $this->get(ProjectResource::getUrl('content', ['record' => $this->project, 'view' => 'unscheduled']))
            ->assertOk()
            ->assertSee('Evergreen idea');
    }

    public function test_no_target_and_over_target_render_safely(): void
    {
        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        ContentItem::factory()->count(3)->forCycle($cycle)->type(ContentType::Blog)->published()->create();

        $this->get(ProjectResource::getUrl('content', ['record' => $bare]))
            ->assertOk()
            ->assertSee('3 / No target')
            ->assertSee('No target configured for this month')
            ->assertDontSee('3 / 0');

        ContentItem::factory()->count(10)->forCycle($this->september)->type(ContentType::Blog)->published()->create();

        $this->get(ProjectResource::getUrl('content', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('10 / 8')
            ->assertSee('2 over target')
            ->assertSee('data-blogs-remaining="0"', false);
    }

    public function test_search_filters_and_status_transitions(): void
    {
        $writer = User::factory()->seoExecutive()->create();
        $this->project->teamMembers()->attach($writer);
        $keyword = Keyword::factory()->forProject($this->project)->create(['keyword' => 'villa rentals']);
        $blog = ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->status(ContentStatus::Approved)->targeting($keyword)->assignedTo($writer)->create(['title' => 'Villa guide']);
        $page = ContentItem::factory()->forCycle($this->september)->type(ContentType::ServicePage)->status(ContentStatus::Writing)->create(['title' => 'Services overview']);

        $this->page()
            ->searchTable('villa rentals')->assertCanSeeTableRecords([$blog])->assertCanNotSeeTableRecords([$page])
            ->searchTable('overview')->assertCanSeeTableRecords([$page])->assertCanNotSeeTableRecords([$blog])
            ->searchTable('')
            ->filterTable('content_type', 'service_page')->assertCanSeeTableRecords([$page])->assertCanNotSeeTableRecords([$blog])
            ->resetTableFilters()
            ->filterTable('status', 'approved')->assertCanSeeTableRecords([$blog])->assertCanNotSeeTableRecords([$page])
            ->resetTableFilters()
            ->filterTable('assigned_user_id', $writer->id)->assertCanSeeTableRecords([$blog])->assertCanNotSeeTableRecords([$page])
            ->resetTableFilters()
            ->assertTableActionHidden('advance', $blog)
            ->assertTableActionVisible('publish', $blog)
            ->callTableAction('publish', $blog, data: ['monthly_cycle_id' => $this->september->id, 'published_at' => '2026-09-14 09:00', 'published_url' => 'https://site.example/blog/villa-guide'])
            ->assertNotified('Content published')
            ->assertTableActionHidden('publish', $blog)
            ->callTableAction('advance', $page)
            ->assertNotified('Status updated')
            ->callTableAction('setStatus', $blog, data: ['status' => 'review'])
            ->assertNotified('Status updated');

        $this->assertSame(ContentStatus::Review, $page->fresh()->status);

        $fresh = $blog->fresh();
        $this->assertSame(ContentStatus::Review, $fresh->status);
        $this->assertNull($fresh->published_at);
        $this->assertNull($fresh->published_url);

        $this->get(ProjectResource::getUrl('content', ['record' => $this->project]))->assertSee('0 / 8');
    }

    public function test_locked_months_are_read_only_in_the_ui(): void
    {
        $frozen = ContentItem::factory()->forCycle($this->september)->type(ContentType::Blog)->published()->create(['title' => 'Frozen post']);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->page()
            ->assertSee('Locked')
            ->assertTableActionHidden('edit', $frozen)
            ->assertTableActionHidden('setStatus', $frozen)
            ->mountTableAction('setStatus', $frozen)
            ->callMountedTableAction()
            ->callAction('createContent', data: ['title' => 'Late post', 'content_type' => 'blog', 'status' => 'planned', 'monthly_cycle_id' => $this->september->id])
            ->assertHasFormErrors(['monthly_cycle_id']);

        $this->assertSame('Frozen post', $frozen->fresh()->title);
        $this->assertTrue($frozen->fresh()->isPublished());
        $this->assertSame(1, ContentItem::query()->count());

        $this->page()
            ->callAction('createContent', data: ['title' => 'October post', 'content_type' => 'blog', 'status' => 'planned', 'monthly_cycle_id' => $october->id])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $october->contentItems()->count());
    }
}
