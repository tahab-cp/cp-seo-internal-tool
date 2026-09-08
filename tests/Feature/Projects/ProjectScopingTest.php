<?php

namespace Tests\Feature\Projects;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Project::scopeAccessibleBy() is the single source of truth for visibility.
 * These tests prove it through the model, the Filament list, global search
 * and the client relation manager.
 */
class ProjectScopingTest extends TestCase
{
    use RefreshDatabase;

    protected User $executive;

    protected Project $owned;

    protected Project $member;

    protected Project $unrelated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executive = User::factory()->seoExecutive()->create();
        $other = User::factory()->seoExecutive()->create();

        $this->owned = Project::factory()->ownedBy($this->executive)->create(['name' => 'Owned Project']);
        $this->member = Project::factory()->ownedBy($other)->withTeam([$this->executive])->create(['name' => 'Member Project']);
        $this->unrelated = Project::factory()->ownedBy($other)->create(['name' => 'Secret Project']);
    }

    public function test_the_query_scope_returns_only_owned_and_member_projects_for_an_executive(): void
    {
        $ids = Project::query()->accessibleBy($this->executive)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->owned->id, $this->member->id], $ids);
        $this->assertEqualsCanonicalizing($ids, $this->executive->accessibleProjects()->pluck('id')->all());
    }

    public function test_the_query_scope_returns_every_project_for_admins_and_managers(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->assertSame(3, Project::query()->accessibleBy($user)->count());
        }
    }

    public function test_the_query_scope_returns_nothing_for_users_without_project_permissions(): void
    {
        $this->assertSame(0, Project::query()->accessibleBy(null)->count());
        $this->assertSame(0, Project::query()->accessibleBy(User::factory()->create())->count());
        $this->assertSame(0, Project::query()->accessibleBy(User::factory()->seoExecutive()->inactive()->create())->count());
    }

    public function test_the_project_list_shows_an_executive_only_their_projects(): void
    {
        $this->actingAs($this->executive);

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$this->owned, $this->member])
            ->assertCanNotSeeTableRecords([$this->unrelated])
            ->assertCountTableRecords(2)
            ->searchTable('Secret')
            ->assertCountTableRecords(0);

        $this->assertEqualsCanonicalizing(
            [$this->owned->id, $this->member->id],
            ProjectResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    public function test_the_project_list_shows_managers_every_project(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$this->owned, $this->member, $this->unrelated])
            ->assertCountTableRecords(3);
    }

    public function test_global_search_does_not_leak_unrelated_projects(): void
    {
        $this->actingAs($this->executive);

        $this->assertSame(
            ['Member Project', 'Owned Project'],
            ProjectResource::getGlobalSearchResults('Project')
                ->map(fn (GlobalSearchResult $result): string => $result->title)
                ->sort()
                ->values()
                ->all(),
        );

        $this->assertCount(0, ProjectResource::getGlobalSearchResults('Secret'));

        $this->actingAs(User::factory()->seoManager()->create());

        $this->assertCount(1, ProjectResource::getGlobalSearchResults('Secret'));
    }

    public function test_global_search_matches_client_name_and_website(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $client = Client::factory()->create(['name' => 'Afzal']);
        Project::factory()->forClient($client)->create(['name' => 'Casa Botanica', 'website_url' => 'https://casabotanica.example']);

        $this->assertCount(1, ProjectResource::getGlobalSearchResults('Afzal'));
        $this->assertCount(1, ProjectResource::getGlobalSearchResults('casabotanica'));
    }

    public function test_the_client_relation_manager_is_scoped_as_well(): void
    {
        $client = Client::factory()->create();
        $visible = Project::factory()->forClient($client)->ownedBy($this->executive)->create();
        $hidden = Project::factory()->forClient($client)->create();

        $this->actingAs(User::factory()->seoManager()->create());

        Livewire::test(ProjectsRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ])->assertCanSeeTableRecords([$visible, $hidden]);

        // Executives never reach client pages, but the table query is still scoped.
        $this->actingAs($this->executive);

        Livewire::test(ProjectsRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ])
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    public function test_archived_projects_are_hidden_from_executives_but_reachable_by_managers(): void
    {
        $this->owned->delete();

        $this->actingAs($this->executive);
        $this->assertSame([$this->member->id], ProjectResource::getEloquentQuery()->pluck('id')->all());
        $this->get(ProjectResource::getUrl('view', ['record' => $this->owned]))->assertNotFound();

        $this->actingAs(User::factory()->seoManager()->create());
        $this->assertSame(3, ProjectResource::getEloquentQuery()->count());
        $this->get(ProjectResource::getUrl('view', ['record' => $this->owned]))->assertOk();

        Livewire::test(ListProjects::class)
            ->assertCanNotSeeTableRecords([$this->owned])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$this->owned]);
    }
}
