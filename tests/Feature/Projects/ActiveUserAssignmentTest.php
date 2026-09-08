<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\SyncProjectTeamAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Enums\ProjectStatus;
use App\Exceptions\InactiveUserAssignmentException;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\ActiveUserGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Invariant: the primary SEO owner and every additional team member must be
 * an active user. Enforced at the request boundary (explicit form rules,
 * independent of the select options) and in the domain Actions.
 */
class ActiveUserAssignmentTest extends TestCase
{
    use RefreshDatabase;

    // -- Request boundary: crafted Livewire state, bypassing the select options --

    public function test_a_crafted_create_request_cannot_assign_an_inactive_owner_or_member(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $inactive = User::factory()->seoExecutive()->inactive()->create();
        $missing = 999999;

        Livewire::test(CreateProject::class)
            ->set('data.client_id', Client::factory()->create()->id)
            ->set('data.name', 'Crafted')
            ->set('data.website_url', 'https://crafted.example')
            ->set('data.status', ProjectStatus::Active->value)
            ->set('data.primary_seo_user_id', $inactive->id)
            ->set('data.team_member_ids', [$inactive->id, $missing])
            ->call('create')
            ->assertHasFormErrors(['primary_seo_user_id', 'team_member_ids.0', 'team_member_ids.1']);

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_user', 0);
    }

    public function test_a_crafted_edit_request_cannot_assign_an_inactive_owner_or_member(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $active = User::factory()->seoExecutive()->create();
        $inactive = User::factory()->seoExecutive()->inactive()->create();
        $project = Project::factory()->ownedBy($active)->create();

        Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
            ->set('data.primary_seo_user_id', $inactive->id)
            ->set('data.team_member_ids', [$inactive->id])
            ->call('save')
            ->assertHasFormErrors(['primary_seo_user_id', 'team_member_ids.0']);

        $project->refresh();

        $this->assertTrue($project->primarySeoUser->is($active));
        $this->assertSame(0, $project->teamMembers()->count());
    }

    // -- Domain layer: the Actions refuse inactive users regardless of caller --

    public function test_create_project_action_rejects_an_inactive_primary_owner_and_rolls_back(): void
    {
        $inactive = User::factory()->seoExecutive()->inactive()->create();

        try {
            app(CreateProjectAction::class)->handle([
                'client_id' => Client::factory()->create()->id,
                'name' => 'Rejected',
                'website_url' => 'https://rejected.example',
                'primary_seo_user_id' => $inactive->id,
            ]);
            $this->fail('Expected InactiveUserAssignmentException.');
        } catch (InactiveUserAssignmentException $exception) {
            $this->assertStringContainsString('primary SEO owner', $exception->getMessage());
        }

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_create_project_action_rejects_an_inactive_team_member_and_rolls_back(): void
    {
        $inactive = User::factory()->seoExecutive()->inactive()->create();

        $this->expectException(InactiveUserAssignmentException::class);

        try {
            app(CreateProjectAction::class)->handle([
                'client_id' => Client::factory()->create()->id,
                'name' => 'Rejected',
                'website_url' => 'https://rejected.example',
            ], [$inactive->id]);
        } finally {
            // The transaction rolled back: no orphaned project without its team.
            $this->assertDatabaseCount('projects', 0);
            $this->assertDatabaseCount('project_user', 0);
        }
    }

    public function test_sync_team_action_rejects_inactive_or_unknown_members_and_keeps_the_existing_team(): void
    {
        $member = User::factory()->seoExecutive()->create();
        $inactive = User::factory()->seoExecutive()->inactive()->create();
        $project = Project::factory()->withTeam([$member])->create();

        foreach ([[$inactive->id], [$member->id, $inactive->id], [999999]] as $ids) {
            try {
                app(SyncProjectTeamAction::class)->handle($project, $ids);
                $this->fail('Expected InactiveUserAssignmentException.');
            } catch (InactiveUserAssignmentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([$member->id], $project->fresh()->teamMembers->pluck('id')->all());
    }

    public function test_update_project_action_rejects_changing_the_owner_to_an_inactive_user(): void
    {
        $active = User::factory()->seoExecutive()->create();
        $inactive = User::factory()->seoExecutive()->inactive()->create();
        $project = Project::factory()->ownedBy($active)->create(['name' => 'Original']);

        try {
            app(UpdateProjectAction::class)->handle($project, [
                'name' => 'Changed',
                'primary_seo_user_id' => $inactive->id,
            ]);
            $this->fail('Expected InactiveUserAssignmentException.');
        } catch (InactiveUserAssignmentException) {
            $this->addToAssertionCount(1);
        }

        $project->refresh();

        $this->assertSame('Original', $project->name);
        $this->assertTrue($project->primarySeoUser->is($active));
    }

    public function test_update_project_action_still_allows_unrelated_edits_when_an_existing_owner_was_deactivated(): void
    {
        $owner = User::factory()->seoExecutive()->create();
        $project = Project::factory()->ownedBy($owner)->create(['name' => 'Original']);
        $owner->forceFill(['is_active' => false])->save();

        app(UpdateProjectAction::class)->handle($project, ['name' => 'Renamed']);

        $this->assertSame('Renamed', $project->fresh()->name);
        $this->assertSame($owner->id, $project->fresh()->primary_seo_user_id);
    }

    public function test_the_guard_accepts_active_users_and_ignores_empty_values(): void
    {
        $active = User::factory()->seoExecutive()->create();
        $guard = app(ActiveUserGuard::class);

        $guard->ensureActive([], 'x');
        $guard->ensureActive([null, ''], 'x');
        $guard->ensureActive([$active->id, (string) $active->id], 'x');

        $this->addToAssertionCount(3);
    }
}
