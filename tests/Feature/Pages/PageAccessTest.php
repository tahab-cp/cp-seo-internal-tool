<?php

namespace Tests\Feature\Pages;

use App\Filament\Resources\Projects\Pages\ProjectPageDetail;
use App\Filament\Resources\Projects\Pages\ProjectPages;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Page access follows project access through URLs, record binding, table
 * queries, actions and crafted requests.
 */
class PageAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $assigned;

    protected Project $unrelated;

    protected Page $assignedPage;

    protected Page $unrelatedPage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executive = User::factory()->seoExecutive()->create();
        $this->assigned = Project::factory()->ownedBy($this->executive)->create();
        $this->unrelated = Project::factory()->create();
        $this->assignedPage = Page::factory()->forProject($this->assigned)->create(['title' => 'Visible page']);
        $this->unrelatedPage = Page::factory()->forProject($this->unrelated)->create(['title' => 'Secret page']);
    }

    public function test_super_admin_and_seo_manager_can_manage_pages_on_any_project(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->actingAs($user);

            $this->get(ProjectResource::getUrl('pages', ['record' => $this->unrelated]))
                ->assertOk()
                ->assertSee($this->unrelatedPage->fresh()->title);
            $this->get(ProjectResource::getUrl('page', ['record' => $this->unrelated, 'page' => $this->unrelatedPage]))->assertOk();

            Livewire::test(ProjectPages::class, ['record' => $this->unrelated->getRouteKey()])
                ->assertCanSeeTableRecords([$this->unrelatedPage])
                ->assertActionVisible('createPage')
                ->callAction('createPage', data: ['url' => 'https://'.$user->id.'.example/new', 'status' => 'active'])
                ->assertHasNoFormErrors()
                ->assertNotified('Page added')
                ->callTableAction('edit', $this->unrelatedPage, data: ['url' => $this->unrelatedPage->url, 'status' => 'active', 'title' => 'Renamed by '.$user->id])
                ->assertNotified('Page updated');

            $this->assertSame('Renamed by '.$user->id, $this->unrelatedPage->fresh()->title);
            $this->assertTrue($user->can('update', $this->unrelatedPage));
            $this->assertTrue($user->can('managePages', $this->unrelated));
        }

        $this->assertSame(3, $this->unrelated->pages()->count());
    }

    public function test_executive_can_manage_pages_for_an_assigned_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('pages', ['record' => $this->assigned]))->assertOk()->assertSee('Visible page');
        $this->get(ProjectResource::getUrl('page', ['record' => $this->assigned, 'page' => $this->assignedPage]))->assertOk();

        Livewire::test(ProjectPages::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanSeeTableRecords([$this->assignedPage])
            ->callAction('createPage', data: ['url' => 'https://assigned.example/contact', 'status' => 'active', 'title' => 'Contact'])
            ->assertHasNoFormErrors()
            ->callTableAction('markRemoved', $this->assignedPage);

        $this->assertTrue($this->assignedPage->fresh()->isRemoved());
        $this->assertDatabaseHas('pages', ['project_id' => $this->assigned->id, 'title' => 'Contact']);
        $this->assertTrue($this->executive->can('update', $this->assignedPage));
        $this->assertSame([$this->assignedPage->id, Page::query()->where('title', 'Contact')->value('id')], Page::query()->accessibleBy($this->executive)->orderBy('id')->pluck('id')->all());
    }

    public function test_executive_cannot_access_pages_of_an_unrelated_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('pages', ['record' => $this->unrelated]))->assertNotFound();
        $this->get(ProjectResource::getUrl('page', ['record' => $this->unrelated, 'page' => $this->unrelatedPage]))->assertNotFound();
        // Mixing an accessible project with a foreign page id is also a 404.
        $this->get(ProjectResource::getUrl('page', ['record' => $this->assigned, 'page' => $this->unrelatedPage]))->assertNotFound();

        foreach ([
            fn () => Livewire::test(ProjectPages::class, ['record' => $this->unrelated->getRouteKey()]),
            fn () => Livewire::test(ProjectPageDetail::class, ['record' => $this->assigned->getRouteKey(), 'page' => $this->unrelatedPage->getRouteKey()]),
        ] as $mount) {
            try {
                $mount();
                $this->fail('Expected the record to be outside the scoped query.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertFalse($this->executive->can('view', $this->unrelatedPage));
        $this->assertFalse($this->executive->can('update', $this->unrelatedPage));
        $this->assertFalse($this->executive->can('managePages', $this->unrelated));
        $this->assertFalse(Page::query()->accessibleBy($this->executive)->whereKey($this->unrelatedPage->id)->exists());
    }

    public function test_crafted_actions_on_an_unrelated_page_do_nothing(): void
    {
        $this->actingAs($this->executive);

        Livewire::test(ProjectPages::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanNotSeeTableRecords([$this->unrelatedPage])
            ->mountTableAction('markRemoved', $this->unrelatedPage)
            ->callMountedTableAction()
            ->mountTableAction('edit', $this->unrelatedPage)
            ->callMountedTableAction();

        $this->assertFalse($this->unrelatedPage->fresh()->isRemoved());
        $this->assertSame('Secret page', $this->unrelatedPage->fresh()->title);
    }

    public function test_crafted_optimisation_edits_on_another_projects_event_do_nothing(): void
    {
        $foreignEvent = PageOptimization::factory()->forPage($this->unrelatedPage)->create(['notes' => 'Untouched']);

        $this->actingAs($this->executive);

        Livewire::test(ProjectPageDetail::class, ['record' => $this->assigned->getRouteKey(), 'page' => $this->assignedPage->getRouteKey()])
            ->assertCanNotSeeTableRecords([$foreignEvent])
            ->mountTableAction('edit', $foreignEvent)
            ->callMountedTableAction();

        $this->assertSame('Untouched', $foreignEvent->fresh()->notes);
        $this->assertFalse($this->executive->can('update', $foreignEvent));
        $this->assertSame(0, PageOptimization::query()->accessibleBy($this->executive)->count());
    }

    public function test_guests_and_inactive_users_are_blocked(): void
    {
        $this->get(ProjectResource::getUrl('pages', ['record' => $this->assigned]))
            ->assertRedirect(Filament::getLoginUrl());

        $inactive = User::factory()->seoManager()->inactive()->create();

        $this->actingAs($inactive)
            ->get(ProjectResource::getUrl('pages', ['record' => $this->assigned]))
            ->assertForbidden();

        $this->assertSame(0, Page::query()->accessibleBy($inactive)->count());
        $this->assertSame(0, Page::query()->accessibleBy(null)->count());
    }

    public function test_pages_are_not_a_global_sidebar_module_and_have_no_resource(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $models = collect(Filament::getPanel('admin')->getResources())
            ->map(fn (string $resource): string => $resource::getModel())
            ->all();

        $this->assertNotContains(Page::class, $models);
        $this->assertNotContains(PageOptimization::class, $models);

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains('Pages', $labels);

        $this->get('/admin')->assertOk()->assertDontSee('/admin/pages');
        $this->get(ProjectResource::getUrl('view', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('pages', ['record' => $this->assigned]));
    }

    public function test_no_permanent_delete_exists_for_pages_or_optimisations(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $event = PageOptimization::factory()->forPage($this->assignedPage)->create();

        $this->assertFalse($admin->can('delete', $this->assignedPage));
        $this->assertFalse($admin->can('forceDelete', $this->assignedPage));
        $this->assertFalse($admin->can('delete', $event));

        $this->actingAs($admin);

        Livewire::test(ProjectPages::class, ['record' => $this->assigned->getRouteKey()])
            ->assertTableActionDoesNotExist('delete', record: $this->assignedPage)
            ->assertTableActionDoesNotExist('forceDelete', record: $this->assignedPage)
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(ProjectPageDetail::class, ['record' => $this->assigned->getRouteKey(), 'page' => $this->assignedPage->getRouteKey()])
            ->assertTableActionDoesNotExist('delete', record: $event)
            ->assertActionDoesNotExist('delete');
    }
}
