<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Package;
use App\Models\User;

/**
 * Package definition is system configuration: Super Admin only.
 * Assigning a package to a project is a separate project ability.
 */
class PackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManagePackages);
    }

    public function view(User $user, Package $package): bool
    {
        return $user->hasPermission(Permission::ManagePackages);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManagePackages);
    }

    public function update(User $user, Package $package): bool
    {
        return $user->hasPermission(Permission::ManagePackages);
    }

    public function activate(User $user, Package $package): bool
    {
        return $user->hasPermission(Permission::ManagePackages);
    }

    public function deactivate(User $user, Package $package): bool
    {
        return $user->hasPermission(Permission::ManagePackages);
    }

    /**
     * Packages are deactivated, never deleted.
     */
    public function delete(User $user, Package $package): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
