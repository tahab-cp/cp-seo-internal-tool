<?php

namespace Tests\Feature\Users;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_three_system_roles_are_defined(): void
    {
        $this->assertSame(
            ['super_admin', 'seo_manager', 'seo_executive'],
            array_map(fn (UserRole $role): string => $role->value, UserRole::cases()),
        );
    }

    public function test_super_admin_holds_every_permission(): void
    {
        $this->assertSame(Permission::cases(), UserRole::SuperAdmin->permissions());
    }

    public function test_seo_manager_holds_client_permissions_but_no_user_management_permissions(): void
    {
        $this->assertSame([
            Permission::ViewClients,
            Permission::CreateClients,
            Permission::UpdateClients,
            Permission::ArchiveClients,
            Permission::ViewAllProjects,
            Permission::CreateProjects,
            Permission::UpdateProjects,
            Permission::ArchiveProjects,
            Permission::AssignProjectTeam,
            Permission::AssignProjectPackage,
            Permission::ManageProjectTargets,
            Permission::EnsureMonthlyCycles,
            Permission::ManageTaskTemplates,
            Permission::GenerateOnboardingTasks,
            Permission::ManageReportSections,
            Permission::FinalizeReports,
        ], UserRole::SeoManager->permissions());

        $this->assertFalse(UserRole::SeoManager->hasPermission(Permission::ManagePackages));
        $this->assertFalse(UserRole::SeoManager->hasPermission(Permission::UnlockReports));
        $this->assertFalse(UserRole::SeoExecutive->hasPermission(Permission::UnlockReports));
        $this->assertTrue(UserRole::SuperAdmin->hasPermission(Permission::UnlockReports));

        $this->assertFalse(UserRole::SeoManager->hasPermission(Permission::ViewUsers));
        $this->assertFalse(UserRole::SeoManager->hasPermission(Permission::CreateUsers));
        $this->assertFalse(UserRole::SeoManager->hasPermission(Permission::UpdateUsers));
        $this->assertFalse(UserRole::SeoManager->hasPermission(Permission::ActivateUsers));
        $this->assertFalse(UserRole::SeoManager->hasPermission(Permission::AssignRoles));
    }

    public function test_seo_executive_holds_no_user_management_permissions(): void
    {
        $this->assertSame([Permission::ViewAssignedProjects], UserRole::SeoExecutive->permissions());
        $this->assertFalse(UserRole::SeoExecutive->hasPermission(Permission::ViewAllProjects));
        $this->assertFalse(UserRole::SeoExecutive->hasPermission(Permission::CreateProjects));
    }

    public function test_the_role_seeder_creates_each_system_role_once(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('roles', 3);

        foreach (UserRole::cases() as $role) {
            $this->assertDatabaseHas('roles', [
                'key' => $role->value,
                'name' => $role->getLabel(),
                'is_system' => true,
            ]);
        }
    }

    public function test_roles_are_resolved_by_key_and_cast_to_the_enum(): void
    {
        $role = Role::forKey(UserRole::SeoManager);

        $this->assertSame(UserRole::SeoManager, $role->key);
        $this->assertTrue($role->is(Role::forKey(UserRole::SeoManager)));
    }
}
