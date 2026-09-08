<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\ArchiveProjectAction;
use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\SyncProjectTeamAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_can_have_multiple_projects(): void
    {
        $afzal = Client::factory()->create(['name' => 'Afzal']);

        foreach (['Casa Botanica', 'Project B', 'Project C'] as $name) {
            Project::factory()->forClient($afzal)->create(['name' => $name]);
        }

        $this->assertSame(3, $afzal->projects()->count());
        $this->assertEqualsCanonicalizing(
            ['Casa Botanica', 'Project B', 'Project C'],
            $afzal->projects->pluck('name')->all(),
        );
    }

    public function test_a_project_belongs_to_exactly_one_client(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->forClient($client)->create();

        $this->assertTrue($project->client->is($client));
        $this->assertSame($client->id, $project->client_id);
        $this->assertSame(0, Client::query()->whereKeyNot($client->id)->whereHas('projects', fn ($q) => $q->whereKey($project->id))->count());
    }

    public function test_a_project_requires_a_client(): void
    {
        $this->expectException(QueryException::class);

        Project::factory()->create(['client_id' => 999999]);
    }

    public function test_status_is_cast_to_the_enum(): void
    {
        $project = Project::factory()->onboarding()->create();

        $this->assertSame(ProjectStatus::Onboarding, $project->fresh()->status);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'onboarding']);
    }

    public function test_primary_owner_and_team_relationships_work_from_both_sides(): void
    {
        $owner = User::factory()->seoExecutive()->create();
        $member = User::factory()->seoExecutive()->create();
        $project = Project::factory()->ownedBy($owner)->withTeam([$member])->create();

        $this->assertTrue($project->primarySeoUser->is($owner));
        $this->assertTrue($project->teamMembers->contains($member));
        $this->assertTrue($owner->primaryProjects->contains($project));
        $this->assertTrue($member->teamProjects->contains($project));
        $this->assertTrue($project->isAccessibleBy($owner));
        $this->assertTrue($project->isAccessibleBy($member));
        $this->assertFalse($project->isAccessibleBy(User::factory()->seoExecutive()->create()));
    }

    public function test_duplicate_team_membership_is_prevented_by_the_database(): void
    {
        $member = User::factory()->seoExecutive()->create();
        $project = Project::factory()->withTeam([$member])->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $project->teamMembers()->attach($member->id);
    }

    public function test_sync_team_action_deduplicates_and_excludes_the_primary_owner(): void
    {
        $owner = User::factory()->seoExecutive()->create();
        [$alpha, $beta] = User::factory()->count(2)->seoExecutive()->create();
        $project = Project::factory()->ownedBy($owner)->create();

        app(SyncProjectTeamAction::class)->handle($project, [$owner->id, $alpha->id, $alpha->id, (string) $beta->id]);

        $this->assertEqualsCanonicalizing([$alpha->id, $beta->id], $project->teamMembers->pluck('id')->all());
        $this->assertSame(2, $project->teamMembers()->count());

        // Existing members keep their descriptive role when the list is re-synced.
        $project->teamMembers()->updateExistingPivot($alpha->id, ['project_role' => 'Content']);
        app(SyncProjectTeamAction::class)->handle($project, [$alpha->id]);

        $this->assertSame('Content', $project->teamMembers()->first()->pivot->project_role);
        $this->assertSame([$alpha->id], $project->fresh()->teamMembers->pluck('id')->all());
    }

    public function test_create_project_action_creates_the_project_and_team_in_one_workflow(): void
    {
        $client = Client::factory()->create();
        $owner = User::factory()->seoExecutive()->create();
        $member = User::factory()->seoExecutive()->create();

        $project = app(CreateProjectAction::class)->handle([
            'client_id' => $client->id,
            'name' => 'Casa Botanica',
            'website_url' => 'https://casabotanica.example',
            'primary_seo_user_id' => $owner->id,
        ], [$owner->id, $member->id]);

        $this->assertTrue($project->exists);
        $this->assertSame(ProjectStatus::Onboarding, $project->status);
        $this->assertTrue($project->client->is($client));
        $this->assertTrue($project->primarySeoUser->is($owner));
        $this->assertSame([$member->id], $project->teamMembers->pluck('id')->all());
    }

    public function test_update_project_action_removes_a_promoted_member_from_the_team(): void
    {
        [$owner, $alpha] = User::factory()->count(2)->seoExecutive()->create();
        $project = Project::factory()->ownedBy($owner)->withTeam([$alpha])->create();

        app(UpdateProjectAction::class)->handle($project, [
            'name' => 'Renamed',
            'primary_seo_user_id' => $alpha->id,
        ]);

        $project->refresh();

        $this->assertSame('Renamed', $project->name);
        $this->assertTrue($project->primarySeoUser->is($alpha));
        $this->assertSame(0, $project->teamMembers()->count());
    }

    public function test_archiving_soft_deletes_without_destroying_history(): void
    {
        $client = Client::factory()->create();
        $member = User::factory()->seoExecutive()->create();
        $project = Project::factory()->forClient($client)->withTeam([$member])->create();

        app(ArchiveProjectAction::class)->handle($project);
        app(ArchiveProjectAction::class)->handle($project);

        $this->assertSoftDeleted($project);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $member->id]);
        $this->assertTrue(Project::withTrashed()->findOrFail($project->id)->client->is($client));
        $this->assertSame(1, $client->projects()->withTrashed()->count());

        Project::withTrashed()->findOrFail($project->id)->restore();

        $this->assertNotSoftDeleted($project);
        $this->assertSame(1, $client->projects()->count());
    }

    public function test_soft_deleting_a_client_keeps_its_projects_and_hard_delete_is_blocked(): void
    {
        $client = Client::factory()->create();
        $project = Project::factory()->forClient($client)->create();

        $client->delete();

        $this->assertSoftDeleted($client);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'client_id' => $client->id, 'deleted_at' => null]);
        $this->assertTrue(Project::query()->findOrFail($project->id)->client()->withTrashed()->first()->is($client));

        $this->expectException(QueryException::class);

        $client->forceDelete();
    }

    public function test_deleting_a_user_never_deletes_their_projects(): void
    {
        $owner = User::factory()->seoExecutive()->create();
        $project = Project::factory()->ownedBy($owner)->withTeam([User::factory()->seoExecutive()->create()])->create();

        $owner->delete();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'primary_seo_user_id' => null, 'deleted_at' => null]);
        $this->assertSame(1, $project->teamMembers()->count());
    }
}
