<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_a_project_with_owner_and_team(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $client = Client::factory()->create();
        $package = Package::factory()->withTargets()->create();
        $owner = User::factory()->seoExecutive()->create();
        [$alpha, $beta] = User::factory()->count(2)->seoExecutive()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => $client->id,
                'package_id' => $package->id,
                'name' => 'Casa Botanica',
                'website_url' => 'https://casabotanica.example',
                'status' => ProjectStatus::Onboarding->value,
                'target_location' => 'London',
                'start_date' => '2026-09-01',
                'end_date' => '2027-08-31',
                'primary_seo_user_id' => $owner->id,
                'team_member_ids' => [$alpha->id, $beta->id],
                'notes' => 'Kick-off booked.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->where('name', 'Casa Botanica')->firstOrFail();

        $this->assertTrue($project->client->is($client));
        $this->assertTrue($project->package->is($package));
        $this->assertSame(ProjectStatus::Onboarding, $project->status);
        $this->assertSame('London', $project->target_location);
        $this->assertSame('2026-09-01', $project->start_date->toDateString());
        $this->assertSame('2027-08-31', $project->end_date->toDateString());
        $this->assertTrue($project->primarySeoUser->is($owner));
        $this->assertEqualsCanonicalizing([$alpha->id, $beta->id], $project->teamMembers->pluck('id')->all());
        $this->assertSame('Kick-off booked.', $project->notes);
    }

    public function test_seo_manager_can_create_a_project(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $client = Client::factory()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => $client->id,
                'package_id' => Package::factory()->create()->id,
                'name' => 'Project B',
                'website_url' => 'https://project-b.example',
                'status' => ProjectStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', ['name' => 'Project B', 'client_id' => $client->id, 'status' => 'active']);
    }

    public function test_project_validation_rules(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $inactive = User::factory()->seoExecutive()->inactive()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => null,
                'name' => '',
                'website_url' => 'not a url',
                'status' => null,
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-01',
                'primary_seo_user_id' => $inactive->id,
                'team_member_ids' => [$inactive->id],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'client_id' => 'required',
                'name' => 'required',
                'website_url' => 'url',
                'status' => 'required',
                'end_date' => 'after_or_equal',
                'primary_seo_user_id',
                'team_member_ids.0',
            ]);

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_the_primary_owner_is_never_duplicated_as_a_team_member(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $owner = User::factory()->seoExecutive()->create();
        $member = User::factory()->seoExecutive()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'package_id' => Package::factory()->create()->id,
                'name' => 'Dedupe',
                'website_url' => 'https://dedupe.example',
                'status' => ProjectStatus::Active->value,
                'primary_seo_user_id' => $owner->id,
                'team_member_ids' => [$owner->id, $member->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->where('name', 'Dedupe')->firstOrFail();

        $this->assertTrue($project->primarySeoUser->is($owner));
        $this->assertSame([$member->id], $project->teamMembers->pluck('id')->all());
        $this->assertSame(1, $project->teamMembers()->count());
    }

    public function test_duplicate_team_member_selections_are_rejected_by_validation(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $member = User::factory()->seoExecutive()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'name' => 'Duplicates',
                'website_url' => 'https://duplicates.example',
                'status' => ProjectStatus::Active->value,
                'team_member_ids' => [$member->id, $member->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['team_member_ids.0' => 'distinct']);

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_project_can_be_edited_and_its_team_re_synced(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        [$owner, $alpha, $beta] = User::factory()->count(3)->seoExecutive()->create();
        $project = Project::factory()->ownedBy($owner)->withTeam([$alpha])->create(['name' => 'Old']);
        $newClient = Client::factory()->create();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->assertFormSet([
                'name' => 'Old',
                'primary_seo_user_id' => $owner->id,
                'team_member_ids' => [$alpha->id],
            ])
            ->fillForm([
                'client_id' => $newClient->id,
                'name' => 'New',
                'status' => ProjectStatus::Paused->value,
                // Promote alpha to owner and add beta: alpha must leave the member list.
                'primary_seo_user_id' => $alpha->id,
                'team_member_ids' => [$alpha->id, $beta->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();

        $this->assertSame('New', $project->name);
        $this->assertTrue($project->client->is($newClient));
        $this->assertSame(ProjectStatus::Paused, $project->status);
        $this->assertTrue($project->primarySeoUser->is($alpha));
        $this->assertSame([$beta->id], $project->teamMembers->pluck('id')->all());
    }

    public function test_archive_soft_deletes_and_keeps_relationships(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $client = Client::factory()->create();
        $member = User::factory()->seoExecutive()->create();
        $project = Project::factory()->forClient($client)->withTeam([$member])->create();

        Livewire::test(ListProjects::class)
            ->assertTableActionVisible('archive', $project)
            ->callTableAction('archive', $project)
            ->assertNotified('Project archived');

        $this->assertSoftDeleted($project);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $member->id]);

        $archived = Project::withTrashed()->findOrFail($project->id);
        $this->assertTrue($archived->client->is($client));
        $this->assertTrue($archived->teamMembers->contains($member));
        $this->assertSame(0, $client->projects()->count());
        $this->assertSame(1, $client->projects()->withTrashed()->count());
    }

    public function test_an_archived_project_can_be_restored_by_a_manager(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $project = Project::factory()->create();
        $project->delete();

        Livewire::test(ListProjects::class)
            ->filterTable('trashed', true)
            ->assertTableActionHidden('archive', $project)
            ->assertTableActionVisible('restore', $project)
            ->callTableAction('restore', $project)
            ->assertNotified('Project restored');

        $this->assertNotSoftDeleted($project);
    }

    public function test_archive_and_restore_work_from_the_view_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $project = Project::factory()->create();

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertActionVisible('archive')
            ->assertActionHidden('restore')
            ->callAction('archive')
            ->assertNotified('Project archived');

        $this->assertSoftDeleted($project);

        Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
            ->assertActionHidden('archive')
            ->assertActionHidden('edit')
            ->assertActionVisible('restore')
            ->callAction('restore')
            ->assertNotified('Project restored');

        $this->assertNotSoftDeleted($project);
    }

    public function test_there_is_no_permanent_delete_anywhere_in_the_project_ui(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $project = Project::factory()->create();

        Livewire::test(ListProjects::class)
            ->assertTableActionDoesNotExist('delete', record: $project)
            ->assertTableActionDoesNotExist('forceDelete', record: $project)
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('forceDelete');

        foreach ([ViewProject::class, EditProject::class] as $page) {
            Livewire::test($page, ['record' => $project->getRouteKey()])
                ->assertActionDoesNotExist('delete')
                ->assertActionDoesNotExist('forceDelete');
        }
    }

    public function test_the_list_supports_search_and_filters(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $client = Client::factory()->create(['name' => 'Afzal']);
        $owner = User::factory()->seoExecutive()->create();

        $casa = Project::factory()->forClient($client)->ownedBy($owner)->create([
            'name' => 'Casa Botanica',
            'website_url' => 'https://casabotanica.example',
        ]);
        $other = Project::factory()->paused()->create([
            'name' => 'Other',
            'website_url' => 'https://other.example',
        ]);

        Livewire::test(ListProjects::class)
            ->assertCanSeeTableRecords([$casa, $other])
            ->searchTable('Afzal')
            ->assertCanSeeTableRecords([$casa])
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('other.example')
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$casa])
            ->searchTable('')
            ->filterTable('status', ProjectStatus::Paused->value)
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$casa])
            ->resetTableFilters()
            ->filterTable('client_id', $client->id)
            ->assertCanSeeTableRecords([$casa])
            ->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->filterTable('primary_seo_user_id', $owner->id)
            ->assertCanSeeTableRecords([$casa])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_client_detail_lists_that_clients_projects(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $afzal = Client::factory()->create(['name' => 'Afzal']);
        $projects = collect([
            Project::factory()->forClient($afzal)->create(['name' => 'Casa Botanica']),
            Project::factory()->forClient($afzal)->create(['name' => 'Project B']),
            Project::factory()->forClient($afzal)->create(['name' => 'Project C']),
        ]);
        $elsewhere = Project::factory()->create();

        Livewire::test(ProjectsRelationManager::class, [
            'ownerRecord' => $afzal,
            'pageClass' => ViewClient::class,
        ])
            ->assertCanSeeTableRecords($projects)
            ->assertCanNotSeeTableRecords([$elsewhere])
            ->assertTableActionDoesNotExist('delete', record: $projects->first());

        $this->get(ProjectResource::getUrl('create', ['client' => $afzal->id]))->assertOk();
    }

    public function test_the_create_page_prefills_the_client_from_the_query_string(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $client = Client::factory()->create();

        $this->get(ProjectResource::getUrl('create', ['client' => $client->id]))
            ->assertOk()
            ->assertSee($client->name);
    }
}
