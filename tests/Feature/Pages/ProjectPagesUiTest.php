<?php

namespace Tests\Feature\Pages;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Packages\SyncPackageTargetsAction;
use App\Enums\MonthlyCycleStatus;
use App\Filament\Resources\Projects\Pages\ProjectPageDetail;
use App\Filament\Resources\Projects\Pages\ProjectPages;
use App\Filament\Resources\Projects\ProjectResource;
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

class ProjectPagesUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Package $package;

    protected Project $project;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->package = Package::factory()->withTargets([
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 8],
        ])->create();
        $this->project = Project::factory()->withPackage($this->package)->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));

        $this->actingAs($this->manager);
    }

    protected function detail(Page $page)
    {
        return Livewire::test(ProjectPageDetail::class, ['record' => $this->project->getRouteKey(), 'page' => $page->getRouteKey()]);
    }

    public function test_page_form_validation_and_creation(): void
    {
        $existing = Page::factory()->forProject($this->project)->create(['url' => 'https://site.example/dup']);

        Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()])
            ->callAction('createPage', data: ['url' => 'not a url', 'status' => null, 'title' => str_repeat('t', 256)])
            ->assertHasFormErrors(['url' => 'url', 'status' => 'required', 'title' => 'max']);

        Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()])
            ->callAction('createPage', data: ['url' => $existing->url, 'status' => 'active'])
            ->assertHasFormErrors(['url' => 'unique']);

        Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()])
            ->callAction('createPage', data: ['url' => 'https://site.example/services/seo', 'status' => 'active', 'title' => 'SEO services', 'page_type' => 'service'])
            ->assertHasNoFormErrors()
            ->assertNotified('Page added');

        $page = Page::query()->where('url', 'https://site.example/services/seo')->firstOrFail();

        $this->assertSame('/services/seo', $page->path);
        $this->assertSame('service', $page->page_type);

        // The same URL on another project is allowed.
        $other = Project::factory()->create();
        $this->actingAs(User::factory()->superAdmin()->create());
        Livewire::test(ProjectPages::class, ['record' => $other->getRouteKey()])
            ->callAction('createPage', data: ['url' => 'https://site.example/services/seo', 'status' => 'active'])
            ->assertHasNoFormErrors();
    }

    public function test_the_list_shows_last_optimised_searches_and_filters(): void
    {
        $seo = Page::factory()->forProject($this->project)->create(['url' => 'https://site.example/seo', 'title' => 'SEO', 'page_type' => 'service']);
        $blog = Page::factory()->forProject($this->project)->draft()->create(['url' => 'https://site.example/blog/one', 'title' => 'Blog one', 'page_type' => 'blog']);
        PageOptimization::factory()->forPage($seo)->forCycle($this->september)->create(['optimized_at' => '2026-09-12 09:00:00']);

        $list = Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()]);

        $list->assertCanSeeTableRecords([$seo, $blog])
            ->assertSee('12 Sep 2026')
            ->assertSee('Never')
            ->searchTable('blog/one')->assertCanSeeTableRecords([$blog])->assertCanNotSeeTableRecords([$seo])
            ->searchTable('SEO')->assertCanSeeTableRecords([$seo])->assertCanNotSeeTableRecords([$blog])
            ->searchTable('')
            ->filterTable('status', 'draft')->assertCanSeeTableRecords([$blog])->assertCanNotSeeTableRecords([$seo])
            ->resetTableFilters()
            ->filterTable('page_type', 'service')->assertCanSeeTableRecords([$seo])->assertCanNotSeeTableRecords([$blog]);
    }

    public function test_mark_removed_and_status_workflow_never_soft_delete(): void
    {
        $page = Page::factory()->forProject($this->project)->create();
        PageOptimization::factory()->forPage($page)->forCycle($this->september)->create();

        Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()])
            ->assertTableActionVisible('markRemoved', $page)
            ->callTableAction('markRemoved', $page)
            ->assertTableActionHidden('markRemoved', $page)
            ->callTableAction('setStatus', $page, data: ['status' => 'redirected']);

        $this->assertSame('redirected', $page->fresh()->status->value);
        $this->assertNotSoftDeleted($page);
        $this->assertSame(1, $page->optimizations()->count());

        $this->detail($page)
            ->assertActionVisible('markRemoved')
            ->callAction('markRemoved')
            ->assertActionHidden('markRemoved')
            ->assertActionHidden('recordOptimization')
            ->assertActionVisible('reactivate')
            ->callAction('reactivate');

        $this->assertSame('active', $page->fresh()->status->value);
        $this->assertNotSoftDeleted($page);
    }

    public function test_recording_optimisations_from_the_page_detail_and_history_versus_distinct_progress(): void
    {
        $page = Page::factory()->forProject($this->project)->create(['title' => 'Services']);

        $this->detail($page)
            ->assertActionVisible('recordOptimization')
            ->callAction('recordOptimization', data: [
                'monthly_cycle_id' => $this->september->id,
                'optimized_at' => '2026-09-04 09:00',
                'meta_title_updated' => false,
                'notes' => 'Just looked',
            ])
            ->assertHasFormErrors(['schema_updated']);

        $this->assertDatabaseCount('page_optimizations', 0);

        $this->detail($page)
            ->callAction('recordOptimization', data: [
                'monthly_cycle_id' => $this->september->id,
                'optimized_at' => '2026-09-04 09:00',
                'meta_title_updated' => true,
                'meta_description_updated' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Optimisation recorded');

        $this->detail($page)
            ->callAction('recordOptimization', data: [
                'monthly_cycle_id' => $this->september->id,
                'optimized_at' => '2026-09-20 09:00',
                'content_updated' => true,
                'internal_links_updated' => true,
                'notes' => 'Second pass',
            ])
            ->assertHasNoFormErrors();

        $events = $page->optimizations()->get();

        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (PageOptimization $event): bool => $event->user->is($this->manager)));

        $this->detail($page)
            ->assertCanSeeTableRecords($events)
            ->assertSee('Meta title')
            ->assertSee('Internal links')
            ->assertSee('Second pass')
            ->assertSee('September 2026');

        // Two history rows, but the month counts the page once.
        $this->get(ProjectResource::getUrl('pages', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('data-pages-optimised="1"', false)
            ->assertSee('data-pages-target="8"', false)
            ->assertSee('1 / 8');
    }

    public function test_the_month_selector_uses_each_cycles_own_records_and_snapshot(): void
    {
        $page = Page::factory()->forProject($this->project)->create();
        PageOptimization::factory()->forPage($page)->forCycle($this->september)->create();

        app(SyncPackageTargetsAction::class)->handle($this->package, [
            ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 12],
        ]);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project->fresh(), new CyclePeriod(2026, 10));
        PageOptimization::factory()->count(2)->forPage($page)->forCycle($october)->create();
        PageOptimization::factory()->forPage(Page::factory()->forProject($this->project)->create())->forCycle($october)->create();

        $list = Livewire::test(ProjectPages::class, ['record' => $this->project->getRouteKey()]);

        $list->assertSet('selectedCycleId', $this->september->id)
            ->assertSee('1 / 8')
            ->set('selectedCycleId', $october->id)
            ->assertSee('2 / 12')
            ->assertDontSee('1 / 8');

        // Deep link to a month.
        $this->get(ProjectResource::getUrl('pages', ['record' => $this->project, 'cycle' => $october->id]))
            ->assertOk()
            ->assertSee('2 / 12');
    }

    public function test_no_target_snapshot_is_displayed_safely(): void
    {
        $bare = Project::factory()->create();
        $cycle = app(CreateMonthlyCycleAction::class)->handle($bare, new CyclePeriod(2026, 9));
        PageOptimization::factory()->forPage(Page::factory()->forProject($bare)->create())->forCycle($cycle)->create();

        $this->get(ProjectResource::getUrl('pages', ['record' => $bare]))
            ->assertOk()
            ->assertSee('1 / No target')
            ->assertSee('data-pages-target=""', false)
            ->assertDontSee('1 / 0');
    }

    public function test_locked_cycles_cannot_be_selected_or_edited_from_the_ui(): void
    {
        $page = Page::factory()->forProject($this->project)->create();
        $event = PageOptimization::factory()->forPage($page)->forCycle($this->september)->create(['notes' => 'Frozen']);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));

        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->detail($page)
            ->assertTableActionHidden('edit', $event)
            ->mountTableAction('edit', $event)
            ->callMountedTableAction()
            ->callAction('recordOptimization', data: [
                'monthly_cycle_id' => $this->september->id,
                'optimized_at' => '2026-09-25 09:00',
                'content_updated' => true,
            ])
            ->assertHasFormErrors(['monthly_cycle_id']);

        $this->assertSame('Frozen', $event->fresh()->notes);
        $this->assertSame(1, $this->september->pageOptimizations()->count());

        // October is still open: recording works and the page itself is editable.
        $this->detail($page)
            ->callAction('recordOptimization', data: [
                'monthly_cycle_id' => $october->id,
                'optimized_at' => '2026-10-02 09:00',
                'content_updated' => true,
            ])
            ->assertHasNoFormErrors()
            ->callAction('editPage', data: ['url' => $page->url, 'status' => 'active', 'title' => 'Corrected'])
            ->assertHasNoFormErrors();

        $this->assertSame('Corrected', $page->fresh()->title);
        $this->assertSame(1, $october->pageOptimizations()->count());
    }
}
