<?php

namespace Tests\Feature\Users;

use App\Enums\Permission;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_holds_every_user_management_ability(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->seoExecutive()->create();

        $this->assertTrue($admin->can('viewAny', User::class));
        $this->assertTrue($admin->can('view', $target));
        $this->assertTrue($admin->can('create', User::class));
        $this->assertTrue($admin->can('update', $target));
        $this->assertTrue($admin->can('assignRole', $target));
        $this->assertTrue($admin->can('activate', $target));
        $this->assertTrue($admin->can('deactivate', $target));

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($admin->can($permission->value), $permission->value);
        }
    }

    public function test_seo_manager_cannot_manage_system_roles(): void
    {
        $manager = User::factory()->seoManager()->create();
        $target = User::factory()->seoExecutive()->create();

        $this->assertFalse($manager->can('assignRole', $target));
        $this->assertFalse($manager->can(Permission::AssignRoles->value));
        $this->assertFalse(Gate::forUser($manager)->allows('roles.assign'));
        $this->assertFalse($manager->hasPermission(Permission::AssignRoles));
    }

    public function test_seo_manager_has_no_user_management_abilities(): void
    {
        $manager = User::factory()->seoManager()->create();
        $target = User::factory()->seoExecutive()->create();

        $this->assertFalse($manager->can('viewAny', User::class));
        $this->assertFalse($manager->can('view', $target));
        $this->assertFalse($manager->can('create', User::class));
        $this->assertFalse($manager->can('update', $target));
        $this->assertFalse($manager->can('activate', $target));
        $this->assertFalse($manager->can('deactivate', $target));

        foreach ($this->userAdministrationPermissions() as $permission) {
            $this->assertFalse($manager->can($permission->value), $permission->value);
        }
    }

    /**
     * @return list<Permission>
     */
    protected function userAdministrationPermissions(): array
    {
        return [
            Permission::ViewUsers,
            Permission::CreateUsers,
            Permission::UpdateUsers,
            Permission::ActivateUsers,
            Permission::AssignRoles,
        ];
    }

    public function test_seo_executive_has_no_user_management_abilities(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $target = User::factory()->seoExecutive()->create();

        $this->assertFalse($executive->can('viewAny', User::class));
        $this->assertFalse($executive->can('view', $target));
        $this->assertFalse($executive->can('create', User::class));
        $this->assertFalse($executive->can('update', $target));
        $this->assertFalse($executive->can('assignRole', $target));
        $this->assertFalse($executive->can('deactivate', $target));

        foreach ($this->userAdministrationPermissions() as $permission) {
            $this->assertFalse($executive->can($permission->value), $permission->value);
        }
    }

    public function test_nobody_may_delete_users(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->seoExecutive()->create();

        $this->assertFalse($admin->can('delete', $target));
        $this->assertFalse($admin->can('deleteAny', User::class));
    }

    public function test_users_cannot_change_their_own_role_or_active_status(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->assertFalse($admin->can('assignRole', $admin));
        $this->assertFalse($admin->can('activate', $admin));
        $this->assertFalse($admin->can('deactivate', $admin));
        $this->assertTrue($admin->can('update', $admin));
    }

    public function test_inactive_users_lose_all_permissions(): void
    {
        $admin = User::factory()->superAdmin()->inactive()->create();
        $target = User::factory()->seoExecutive()->create();

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertFalse($admin->can('viewAny', User::class));
        $this->assertFalse($admin->can('assignRole', $target));
        $this->assertFalse($admin->hasPermission(Permission::ViewUsers));
    }

    public function test_seo_manager_cannot_deactivate_a_user_through_a_direct_action_call(): void
    {
        $manager = User::factory()->seoManager()->create();
        $target = User::factory()->seoExecutive()->create();

        $this->actingAs($manager);

        // The page itself is forbidden, so the action can never be mounted or called.
        Livewire::test(ListUsers::class)->assertForbidden();

        $this->assertFalse($manager->can('deactivate', $target));
        $this->assertTrue($target->refresh()->is_active);
    }
}
