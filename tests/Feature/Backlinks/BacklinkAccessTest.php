<?php

namespace Tests\Feature\Backlinks;

use App\Enums\BacklinkStatus;
use App\Filament\Resources\Projects\Pages\ProjectBacklinks;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Backlink;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BacklinkAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $assigned;

    protected Project $unrelated;

    protected Backlink $assignedLink;

    protected Backlink $unrelatedLink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executive = User::factory()->seoExecutive()->create();
        $this->assigned = Project::factory()->ownedBy($this->executive)->create();
        $this->unrelated = Project::factory()->create();
        $this->assignedLink = Backlink::factory()->forProject($this->assigned)->create(['published_url' => 'https://visible.example/post', 'anchor_text' => 'visible anchor']);
        $this->unrelatedLink = Backlink::factory()->forProject($this->unrelated)->create(['published_url' => 'https://secret.example/post', 'anchor_text' => 'secret anchor']);
    }

    public function test_super_admin_and_seo_manager_manage_backlinks_on_any_project(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->actingAs($user);

            $this->get(ProjectResource::getUrl('backlinks', ['record' => $this->unrelated, 'cycle' => 'all']))
                ->assertOk()
                ->assertSee('secret.example');

            Livewire::test(ProjectBacklinks::class, ['record' => $this->unrelated->getRouteKey()])
                ->assertCanSeeTableRecords([$this->unrelatedLink])
                ->assertActionVisible('createBacklink')
                ->callAction('createBacklink', data: [
                    'monthly_cycle_id' => $this->unrelatedLink->monthly_cycle_id,
                    'published_url' => 'https://new.example/'.$user->id,
                    'type' => 'citation',
                    'status' => 'live',
                ])
                ->assertHasNoFormErrors()
                ->assertNotified('Backlink added')
                ->callTableAction('setStatus', $this->unrelatedLink, data: ['status' => 'submitted'])
                ->assertNotified('Status updated');

            $this->assertSame(BacklinkStatus::Submitted, $this->unrelatedLink->fresh()->status);
            $this->assertTrue($user->can('update', $this->unrelatedLink));
            $this->assertTrue($user->can('manageBacklinks', $this->unrelated));
            $this->assertDatabaseHas('backlinks', ['published_url' => 'https://new.example/'.$user->id, 'created_by' => $user->id]);
        }

        $this->assertSame(3, $this->unrelated->backlinks()->count());
    }

    public function test_executive_manages_backlinks_for_an_assigned_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('backlinks', ['record' => $this->assigned]))->assertOk()->assertSee('visible.example');

        Livewire::test(ProjectBacklinks::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanSeeTableRecords([$this->assignedLink])
            ->callAction('createBacklink', data: [
                'monthly_cycle_id' => $this->assignedLink->monthly_cycle_id,
                'published_url' => 'https://executive.example/post',
                'type' => 'guest_post',
                'status' => 'submitted',
            ])
            ->assertHasNoFormErrors()
            ->callTableAction('edit', $this->assignedLink, data: [
                'monthly_cycle_id' => $this->assignedLink->monthly_cycle_id,
                'published_url' => $this->assignedLink->published_url,
                'type' => 'citation',
                'status' => 'live',
                'anchor_text' => 'edited by executive',
            ])
            ->assertNotified('Backlink updated');

        $this->assertSame('edited by executive', $this->assignedLink->fresh()->anchor_text);
        $this->assertDatabaseHas('backlinks', ['published_url' => 'https://executive.example/post', 'created_by' => $this->executive->id]);
        $this->assertSame(2, Backlink::query()->accessibleBy($this->executive)->count());
    }

    public function test_executive_cannot_access_backlinks_of_an_unrelated_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('backlinks', ['record' => $this->unrelated]))->assertNotFound();

        try {
            Livewire::test(ProjectBacklinks::class, ['record' => $this->unrelated->getRouteKey()]);
            $this->fail('Expected the project to be outside the scoped resource query.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($this->executive->can('view', $this->unrelatedLink));
        $this->assertFalse($this->executive->can('update', $this->unrelatedLink));
        $this->assertFalse($this->executive->can('manageBacklinks', $this->unrelated));
        $this->assertFalse(Backlink::query()->accessibleBy($this->executive)->whereKey($this->unrelatedLink->id)->exists());
    }

    public function test_crafted_actions_on_an_unrelated_backlink_do_nothing(): void
    {
        $this->actingAs($this->executive);

        Livewire::test(ProjectBacklinks::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanNotSeeTableRecords([$this->unrelatedLink])
            ->mountTableAction('setStatus', $this->unrelatedLink)
            ->callMountedTableAction()
            ->mountTableAction('edit', $this->unrelatedLink)
            ->callMountedTableAction();

        // A crafted create against another project's cycle is rejected by validation.
        Livewire::test(ProjectBacklinks::class, ['record' => $this->assigned->getRouteKey()])
            ->callAction('createBacklink', data: [
                'monthly_cycle_id' => $this->unrelatedLink->monthly_cycle_id,
                'published_url' => 'https://leak.example/post',
                'type' => 'citation',
                'status' => 'live',
            ])
            ->assertHasFormErrors(['monthly_cycle_id']);

        $this->assertSame('secret anchor', $this->unrelatedLink->fresh()->anchor_text);
        $this->assertSame(BacklinkStatus::Live, $this->unrelatedLink->fresh()->status);
        $this->assertDatabaseMissing('backlinks', ['published_url' => 'https://leak.example/post']);
    }

    public function test_guests_and_inactive_users_are_blocked(): void
    {
        $this->get(ProjectResource::getUrl('backlinks', ['record' => $this->assigned]))
            ->assertRedirect(Filament::getLoginUrl());

        $inactive = User::factory()->seoManager()->inactive()->create();

        $this->actingAs($inactive)
            ->get(ProjectResource::getUrl('backlinks', ['record' => $this->assigned]))
            ->assertForbidden();

        $this->assertSame(0, Backlink::query()->accessibleBy($inactive)->count());
        $this->assertSame(0, Backlink::query()->accessibleBy(null)->count());
    }

    public function test_backlinks_are_not_a_global_sidebar_module_and_have_no_delete(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        $models = collect(Filament::getPanel('admin')->getResources())
            ->map(fn (string $resource): string => $resource::getModel())
            ->all();

        $this->assertNotContains(Backlink::class, $models);

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains('Backlinks', $labels);
        $this->get('/admin')->assertOk()->assertDontSee('/admin/backlinks');
        $this->get(ProjectResource::getUrl('view', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('backlinks', ['record' => $this->assigned]));

        Livewire::test(ProjectBacklinks::class, ['record' => $this->assigned->getRouteKey()])
            ->assertTableActionDoesNotExist('delete', record: $this->assignedLink)
            ->assertTableActionDoesNotExist('forceDelete', record: $this->assignedLink)
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertActionDoesNotExist('import');
    }
}
