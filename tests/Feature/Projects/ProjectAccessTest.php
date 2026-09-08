<?php

namespace Tests\Feature\Projects;

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Project access through direct URLs and Livewire page mounts. Unrelated
 * projects are outside the resource query for an SEO Executive, so the
 * record cannot even be resolved (404); abilities are denied by policy (403).
 */
class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_projects(): void
    {
        $this->get(ProjectResource::getUrl('index'))
            ->assertRedirect(Filament::getLoginUrl());
    }

    public function test_super_admin_can_view_create_and_update_projects(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertCanManageAllProjectPages(Project::factory()->create());
    }

    public function test_seo_manager_can_view_create_and_update_projects(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        $this->assertCanManageAllProjectPages(Project::factory()->create());
    }

    public function test_seo_executive_can_access_a_project_they_own(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $project = Project::factory()->ownedBy($executive)->create();

        $this->actingAs($executive);

        $this->get(ProjectResource::getUrl('index'))->assertOk();
        $this->get(ProjectResource::getUrl('view', ['record' => $project]))
            ->assertOk()
            ->assertSee($project->name);

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])->assertOk();

        $this->assertTrue($executive->can('view', $project));
        $this->assertTrue(ProjectResource::canView($project));
    }

    public function test_seo_executive_can_access_a_project_where_they_are_a_team_member(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $project = Project::factory()->withTeam([$executive])->create();

        $this->actingAs($executive);

        $this->get(ProjectResource::getUrl('view', ['record' => $project]))
            ->assertOk()
            ->assertSee($project->name);

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])->assertOk();

        $this->assertTrue($executive->can('view', $project));
    }

    public function test_seo_executive_cannot_access_an_unrelated_project(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $other = User::factory()->seoExecutive()->create();
        $unrelated = Project::factory()->ownedBy($other)->create();

        $this->actingAs($executive);

        // Direct URLs: the record is outside the scoped query, so it does not resolve.
        $this->get(ProjectResource::getUrl('view', ['record' => $unrelated]))->assertNotFound();
        $this->get(ProjectResource::getUrl('edit', ['record' => $unrelated]))->assertNotFound();

        // Page mounts behave the same way: the record cannot be resolved at all.
        $this->assertRecordCannotBeResolved(fn () => Livewire::test(ViewProject::class, ['record' => $unrelated->getRouteKey()]));
        $this->assertRecordCannotBeResolved(fn () => Livewire::test(EditProject::class, ['record' => $unrelated->getRouteKey()]));

        // And the policy independently denies every ability.
        $this->assertFalse($executive->can('view', $unrelated));
        $this->assertFalse($executive->can('update', $unrelated));
        $this->assertFalse($executive->can('archive', $unrelated));
        $this->assertFalse(ProjectResource::canView($unrelated));
        $this->assertFalse(ProjectResource::canEdit($unrelated));
    }

    public function test_seo_executive_cannot_create_or_edit_even_their_own_projects(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $own = Project::factory()->ownedBy($executive)->create();

        $this->actingAs($executive);

        $this->get(ProjectResource::getUrl('create'))->assertForbidden();
        $this->get(ProjectResource::getUrl('edit', ['record' => $own]))->assertForbidden();

        Livewire::test(CreateProject::class)->assertForbidden();
        Livewire::test(EditProject::class, ['record' => $own->getRouteKey()])->assertForbidden();

        $this->assertFalse(ProjectResource::canCreate());
        $this->assertFalse(ProjectResource::canEdit($own));
        $this->assertFalse($executive->can('assignTeam', $own));
        $this->assertFalse($executive->can('archive', $own));
    }

    public function test_seo_executive_cannot_archive_a_project_through_actions(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $own = Project::factory()->ownedBy($executive)->create();

        $this->actingAs($executive);

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$own])
            ->assertTableActionHidden('archive', $own)
            ->assertTableActionHidden('edit', $own);

        Livewire::test(ViewProject::class, ['record' => $own->getRouteKey()])
            ->assertActionHidden('archive')
            ->assertActionHidden('edit')
            ->mountAction('archive')
            ->callMountedAction();

        $this->assertNotSoftDeleted($own);
    }

    public function test_everyone_with_a_role_sees_projects_in_navigation(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
            User::factory()->seoExecutive()->create(),
        ] as $user) {
            $this->actingAs($user)
                ->get('/admin')
                ->assertOk()
                ->assertSee(ProjectResource::getUrl('index'));
        }
    }

    public function test_inactive_users_cannot_access_projects(): void
    {
        $executive = User::factory()->seoExecutive()->inactive()->create();
        $project = Project::factory()->ownedBy($executive)->create();

        $this->actingAs($executive);

        $this->get(ProjectResource::getUrl('index'))->assertForbidden();
        $this->assertFalse($executive->can('view', $project));
    }

    protected function assertRecordCannotBeResolved(Closure $mount): void
    {
        try {
            $mount();
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the project to be outside the scoped resource query for this user.');
    }

    protected function assertCanManageAllProjectPages(Project $project): void
    {
        $this->get(ProjectResource::getUrl('index'))->assertOk();
        $this->get(ProjectResource::getUrl('create'))->assertOk();
        $this->get(ProjectResource::getUrl('view', ['record' => $project]))->assertOk()->assertSee($project->name);
        $this->get(ProjectResource::getUrl('edit', ['record' => $project]))->assertOk();

        Livewire::test(CreateProject::class)->assertOk();
        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])->assertOk();

        $this->assertTrue(ProjectResource::canViewAny());
        $this->assertTrue(ProjectResource::canCreate());
        $this->assertTrue(ProjectResource::canView($project));
        $this->assertTrue(ProjectResource::canEdit($project));
    }
}
