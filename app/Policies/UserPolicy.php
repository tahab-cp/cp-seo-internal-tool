<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewUsers);
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::ViewUsers);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CreateUsers);
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::UpdateUsers);
    }

    /**
     * Users are deactivated, never deleted, so history stays intact.
     */
    public function delete(User $user, User $target): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    /**
     * A user may never change their own role. This also guarantees that at
     * least one active Super Admin always remains.
     */
    public function assignRole(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::AssignRoles) && ! $user->is($target);
    }

    public function activate(User $user, User $target): bool
    {
        return $this->changeActiveStatus($user, $target);
    }

    public function deactivate(User $user, User $target): bool
    {
        return $this->changeActiveStatus($user, $target);
    }

    /**
     * A user may never activate or deactivate themselves.
     */
    protected function changeActiveStatus(User $user, User $target): bool
    {
        return $user->hasPermission(Permission::ActivateUsers) && ! $user->is($target);
    }
}
