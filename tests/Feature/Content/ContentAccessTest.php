<?php

namespace Tests\Feature\Content;

use App\Enums\ContentStatus;
use App\Filament\Resources\Projects\Pages\ProjectContent;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $assigned;

    protected Project $unrelated;

    protected ContentItem $assignedItem;

    protected ContentItem $unrelatedItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executive = User::factory()->seoExecutive()->create();
        $this->assigned = Project::factory()->ownedBy($this->executive)->create();
        $this->unrelated = Project::factory()->create();
        $this->assignedItem = ContentItem::factory()->forProject($this->assigned)->create(['title' => 'Visible post']);
        $this->unrelatedItem = ContentItem::factory()->forProject($this->unrelated)->create(['title' => 'Secret post']);
    }

    public function test_super_admin_and_seo_manager_manage_content_on_any_project(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->actingAs($user);

            $this->get(ProjectResource::getUrl('content', ['record' => $this->unrelated, 'view' => 'all']))
                ->assertOk()
                ->assertSee($this->unrelatedItem->fresh()->title);

            Livewire::test(ProjectContent::class, ['record' => $this->unrelated->getRouteKey()])
                ->assertCanSeeTableRecords([$this->unrelatedItem])
                ->assertActionVisible('createContent')
                ->callAction('createContent', data: ['title' => 'New by '.$user->id, 'content_type' => 'blog', 'status' => 'idea'])
                ->assertHasNoFormErrors()
                ->assertNotified('Content added')
                ->callTableAction('edit', $this->unrelatedItem, data: ['title' => 'Renamed by '.$user->id, 'content_type' => 'blog', 'status' => 'planned'])
                ->assertNotified('Content updated');

            $this->assertSame('Renamed by '.$user->id, $this->unrelatedItem->fresh()->title);
            $this->assertTrue($user->can('update', $this->unrelatedItem));
            $this->assertTrue($user->can('manageContent', $this->unrelated));
        }

        $this->assertSame(3, $this->unrelated->contentItems()->count());
    }

    public function test_executive_manages_content_for_an_assigned_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('content', ['record' => $this->assigned]))->assertOk()->assertSee('Visible post');

        Livewire::test(ProjectContent::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanSeeTableRecords([$this->assignedItem])
            ->callAction('createContent', data: ['title' => 'Executive post', 'content_type' => 'blog', 'status' => 'planned', 'assigned_user_id' => $this->executive->id])
            ->assertHasNoFormErrors()
            ->callTableAction('advance', $this->assignedItem)
            ->assertNotified('Status updated');

        $this->assertSame(ContentStatus::Writing, $this->assignedItem->fresh()->status);
        $this->assertDatabaseHas('content_items', ['title' => 'Executive post', 'assigned_user_id' => $this->executive->id]);
        $this->assertSame(2, ContentItem::query()->accessibleBy($this->executive)->count());
    }

    public function test_executive_cannot_access_content_of_an_unrelated_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('content', ['record' => $this->unrelated]))->assertNotFound();

        try {
            Livewire::test(ProjectContent::class, ['record' => $this->unrelated->getRouteKey()]);
            $this->fail('Expected the project to be outside the scoped resource query.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($this->executive->can('view', $this->unrelatedItem));
        $this->assertFalse($this->executive->can('update', $this->unrelatedItem));
        $this->assertFalse($this->executive->can('manageContent', $this->unrelated));
        $this->assertFalse(ContentItem::query()->accessibleBy($this->executive)->whereKey($this->unrelatedItem->id)->exists());
    }

    public function test_crafted_actions_and_cross_project_selects_do_nothing(): void
    {
        $outsider = User::factory()->seoExecutive()->create();

        $this->actingAs($this->executive);

        Livewire::test(ProjectContent::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanNotSeeTableRecords([$this->unrelatedItem])
            ->mountTableAction('setStatus', $this->unrelatedItem)
            ->callMountedTableAction()
            ->mountTableAction('edit', $this->unrelatedItem)
            ->callMountedTableAction();

        Livewire::test(ProjectContent::class, ['record' => $this->assigned->getRouteKey()])
            ->callAction('createContent', data: [
                'title' => 'Leak',
                'content_type' => 'blog',
                'status' => 'planned',
                'monthly_cycle_id' => ContentItem::factory()->forProject($this->unrelated)->create()->project->monthlyCycles()->first()?->id ?? 999999,
                'assigned_user_id' => $outsider->id,
            ])
            ->assertHasFormErrors(['monthly_cycle_id', 'assigned_user_id']);

        $this->assertSame('Secret post', $this->unrelatedItem->fresh()->title);
        $this->assertDatabaseMissing('content_items', ['title' => 'Leak']);
    }

    public function test_guests_and_inactive_users_are_blocked(): void
    {
        $this->get(ProjectResource::getUrl('content', ['record' => $this->assigned]))
            ->assertRedirect(Filament::getLoginUrl());

        $inactive = User::factory()->seoManager()->inactive()->create();

        $this->actingAs($inactive)
            ->get(ProjectResource::getUrl('content', ['record' => $this->assigned]))
            ->assertForbidden();

        $this->assertSame(0, ContentItem::query()->accessibleBy($inactive)->count());
        $this->assertSame(0, ContentItem::query()->accessibleBy(null)->count());
    }

    public function test_content_is_not_a_global_sidebar_module_and_has_no_delete(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        $models = collect(Filament::getPanel('admin')->getResources())
            ->map(fn (string $resource): string => $resource::getModel())
            ->all();

        $this->assertNotContains(ContentItem::class, $models);

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains('Content', $labels);
        $this->get('/admin')->assertOk()->assertDontSee('/admin/content');
        $this->get(ProjectResource::getUrl('view', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('content', ['record' => $this->assigned]));

        Livewire::test(ProjectContent::class, ['record' => $this->assigned->getRouteKey()])
            ->assertTableActionDoesNotExist('delete', record: $this->assignedItem)
            ->assertTableActionDoesNotExist('forceDelete', record: $this->assignedItem)
            ->assertTableBulkActionDoesNotExist('delete');
    }
}
