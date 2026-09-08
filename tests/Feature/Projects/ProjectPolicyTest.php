<?php

namespace Tests\Feature\Projects;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_and_seo_manager_hold_every_project_ability(): void
    {
        $project = Project::factory()->create();

        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->assertTrue($user->can('viewAny', Project::class));
            $this->assertTrue($user->can('view', $project));
            $this->assertTrue($user->can('create', Project::class));
            $this->assertTrue($user->can('update', $project));
            $this->assertTrue($user->can('assignTeam', $project));
            $this->assertTrue($user->can('archive', $project));
            $this->assertTrue($user->can('restore', $project));

            foreach ([
                Permission::ViewAllProjects,
                Permission::CreateProjects,
                Permission::UpdateProjects,
                Permission::ArchiveProjects,
                Permission::AssignProjectTeam,
            ] as $permission) {
                $this->assertTrue($user->can($permission->value), $permission->value);
            }
        }
    }

    public function test_seo_executive_may_only_view_owned_or_member_projects(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $owned = Project::factory()->ownedBy($executive)->create();
        $member = Project::factory()->withTeam([$executive])->create();
        $unrelated = Project::factory()->create();

        $this->assertTrue($executive->can('viewAny', Project::class));
        $this->assertTrue($executive->can('view', $owned));
        $this->assertTrue($executive->can('view', $member));
        $this->assertFalse($executive->can('view', $unrelated));

        foreach ([$owned, $member, $unrelated] as $project) {
            $this->assertFalse($executive->can('update', $project));
            $this->assertFalse($executive->can('assignTeam', $project));
            $this->assertFalse($executive->can('archive', $project));
            $this->assertFalse($executive->can('restore', $project));
        }

        $this->assertFalse($executive->can('create', Project::class));
        $this->assertFalse($executive->can(Permission::ViewAllProjects->value));
        $this->assertTrue($executive->can(Permission::ViewAssignedProjects->value));
    }

    public function test_nobody_may_hard_delete_projects_through_the_policy(): void
    {
        $project = Project::factory()->create();

        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
            User::factory()->seoExecutive()->create(),
        ] as $user) {
            $this->assertFalse($user->can('delete', $project));
            $this->assertFalse($user->can('deleteAny', Project::class));
            $this->assertFalse($user->can('forceDelete', $project));
            $this->assertFalse($user->can('forceDeleteAny', Project::class));
        }
    }

    public function test_inactive_users_lose_project_access(): void
    {
        $executive = User::factory()->seoExecutive()->inactive()->create();
        $project = Project::factory()->ownedBy($executive)->create();

        $this->assertFalse($executive->can('viewAny', Project::class));
        $this->assertFalse($executive->can('view', $project));
    }
}
