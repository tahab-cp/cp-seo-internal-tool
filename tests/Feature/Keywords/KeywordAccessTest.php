<?php

namespace Tests\Feature\Keywords;

use App\Filament\Resources\Projects\Pages\ProjectKeywordDetail;
use App\Filament\Resources\Projects\Pages\ProjectKeywords;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Keyword;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Keyword access follows project access through URLs, record binding,
 * table queries, actions and crafted requests.
 */
class KeywordAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $assigned;

    protected Project $unrelated;

    protected Keyword $assignedKeyword;

    protected Keyword $unrelatedKeyword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executive = User::factory()->seoExecutive()->create();
        $this->assigned = Project::factory()->ownedBy($this->executive)->create();
        $this->unrelated = Project::factory()->create();
        $this->assignedKeyword = Keyword::factory()->forProject($this->assigned)->create(['keyword' => 'visible keyword']);
        $this->unrelatedKeyword = Keyword::factory()->forProject($this->unrelated)->create(['keyword' => 'secret keyword']);
    }

    public function test_super_admin_and_seo_manager_can_manage_keywords_on_any_project(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->actingAs($user);

            $this->get(ProjectResource::getUrl('keywords', ['record' => $this->unrelated]))->assertOk()->assertSee($this->unrelatedKeyword->fresh()->keyword);
            $this->get(ProjectResource::getUrl('keyword', ['record' => $this->unrelated, 'keyword' => $this->unrelatedKeyword]))->assertOk();

            Livewire::test(ProjectKeywords::class, ['record' => $this->unrelated->getRouteKey()])
                ->assertCanSeeTableRecords([$this->unrelatedKeyword])
                ->assertActionVisible('createKeyword')
                ->callAction('createKeyword', data: ['keyword' => 'new by '.$user->id, 'status' => 'active'])
                ->assertHasNoFormErrors()
                ->assertNotified('Keyword added')
                ->callTableAction('edit', $this->unrelatedKeyword, data: ['keyword' => 'renamed by '.$user->id, 'status' => 'active'])
                ->assertNotified('Keyword updated');

            $this->assertSame('renamed by '.$user->id, $this->unrelatedKeyword->fresh()->keyword);
            $this->assertTrue($user->can('update', $this->unrelatedKeyword));
            $this->assertTrue($user->can('manageKeywords', $this->unrelated));
            $this->assertTrue($user->can('recordRankings', $this->unrelated));
        }

        $this->assertSame(3, $this->unrelated->keywords()->count());
    }

    public function test_executive_can_manage_keywords_for_an_assigned_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('keywords', ['record' => $this->assigned]))->assertOk()->assertSee('visible keyword');
        $this->get(ProjectResource::getUrl('keyword', ['record' => $this->assigned, 'keyword' => $this->assignedKeyword]))->assertOk();

        Livewire::test(ProjectKeywords::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanSeeTableRecords([$this->assignedKeyword])
            ->callAction('createKeyword', data: ['keyword' => 'executive keyword', 'status' => 'active', 'is_branded' => true])
            ->assertHasNoFormErrors()
            ->callTableAction('setStatus', $this->assignedKeyword, data: ['status' => 'archived']);

        $this->assertTrue($this->assignedKeyword->fresh()->isArchived());
        $this->assertDatabaseHas('keywords', ['project_id' => $this->assigned->id, 'keyword' => 'executive keyword', 'is_branded' => true]);
        $this->assertTrue($this->executive->can('update', $this->assignedKeyword));
        $this->assertSame(2, Keyword::query()->accessibleBy($this->executive)->count());
    }

    public function test_executive_cannot_access_keywords_of_an_unrelated_project(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('keywords', ['record' => $this->unrelated]))->assertNotFound();
        $this->get(ProjectResource::getUrl('keyword', ['record' => $this->unrelated, 'keyword' => $this->unrelatedKeyword]))->assertNotFound();
        // Mixing an accessible project with a foreign keyword id is also a 404.
        $this->get(ProjectResource::getUrl('keyword', ['record' => $this->assigned, 'keyword' => $this->unrelatedKeyword]))->assertNotFound();

        foreach ([
            fn () => Livewire::test(ProjectKeywords::class, ['record' => $this->unrelated->getRouteKey()]),
            fn () => Livewire::test(ProjectKeywordDetail::class, ['record' => $this->assigned->getRouteKey(), 'keyword' => $this->unrelatedKeyword->getRouteKey()]),
        ] as $mount) {
            try {
                $mount();
                $this->fail('Expected the record to be outside the scoped query.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertFalse($this->executive->can('view', $this->unrelatedKeyword));
        $this->assertFalse($this->executive->can('update', $this->unrelatedKeyword));
        $this->assertFalse($this->executive->can('manageKeywords', $this->unrelated));
        $this->assertFalse(Keyword::query()->accessibleBy($this->executive)->whereKey($this->unrelatedKeyword->id)->exists());
        $this->assertSame(0, RankingSnapshot::query()->accessibleBy($this->executive)->whereHas('keyword', fn ($q) => $q->whereKey($this->unrelatedKeyword->id))->count());
    }

    public function test_crafted_actions_on_unrelated_records_do_nothing(): void
    {
        $foreignSnapshot = RankingSnapshot::factory()->forKeyword($this->unrelatedKeyword)->at('2026-09-01 09:00', 9)->create();

        $this->actingAs($this->executive);

        Livewire::test(ProjectKeywords::class, ['record' => $this->assigned->getRouteKey()])
            ->assertCanNotSeeTableRecords([$this->unrelatedKeyword])
            ->mountTableAction('setStatus', $this->unrelatedKeyword)
            ->callMountedTableAction()
            ->mountTableAction('edit', $this->unrelatedKeyword)
            ->callMountedTableAction();

        Livewire::test(ProjectKeywordDetail::class, ['record' => $this->assigned->getRouteKey(), 'keyword' => $this->assignedKeyword->getRouteKey()])
            ->assertCanNotSeeTableRecords([$foreignSnapshot])
            ->mountTableAction('edit', $foreignSnapshot)
            ->callMountedTableAction();

        $this->assertSame('secret keyword', $this->unrelatedKeyword->fresh()->keyword);
        $this->assertSame('active', $this->unrelatedKeyword->fresh()->status->value);
        $this->assertSame(9, $foreignSnapshot->fresh()->position);
        $this->assertFalse($this->executive->can('update', $foreignSnapshot));
    }

    public function test_guests_and_inactive_users_are_blocked(): void
    {
        $this->get(ProjectResource::getUrl('keywords', ['record' => $this->assigned]))
            ->assertRedirect(Filament::getLoginUrl());

        $inactive = User::factory()->seoManager()->inactive()->create();

        $this->actingAs($inactive)
            ->get(ProjectResource::getUrl('keywords', ['record' => $this->assigned]))
            ->assertForbidden();

        $this->assertSame(0, Keyword::query()->accessibleBy($inactive)->count());
        $this->assertSame(0, Keyword::query()->accessibleBy(null)->count());
    }

    public function test_keywords_are_not_a_global_sidebar_module_and_nothing_is_deletable(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin);

        $models = collect(Filament::getPanel('admin')->getResources())
            ->map(fn (string $resource): string => $resource::getModel())
            ->all();

        $this->assertNotContains(Keyword::class, $models);
        $this->assertNotContains(RankingSnapshot::class, $models);

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains('Keywords', $labels);
        $this->get('/admin')->assertOk()->assertDontSee('/admin/keywords');
        $this->get(ProjectResource::getUrl('view', ['record' => $this->assigned]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('keywords', ['record' => $this->assigned]));

        $snapshot = RankingSnapshot::factory()->forKeyword($this->assignedKeyword)->create();

        $this->assertFalse($admin->can('delete', $this->assignedKeyword));
        $this->assertFalse($admin->can('forceDelete', $this->assignedKeyword));
        $this->assertFalse($admin->can('delete', $snapshot));

        Livewire::test(ProjectKeywords::class, ['record' => $this->assigned->getRouteKey()])
            ->assertTableActionDoesNotExist('delete', record: $this->assignedKeyword)
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(ProjectKeywordDetail::class, ['record' => $this->assigned->getRouteKey(), 'keyword' => $this->assignedKeyword->getRouteKey()])
            ->assertTableActionDoesNotExist('delete', record: $snapshot)
            ->assertActionDoesNotExist('delete');
    }
}
