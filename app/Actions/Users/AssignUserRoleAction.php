<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignUserRoleAction
{
    /**
     * Give the user exactly one system role, replacing any existing role.
     *
     * The role_user table carries a unique index on user_id, so a user can
     * never hold two roles at the database level either.
     */
    public function handle(User $user, UserRole $role): User
    {
        DB::transaction(function () use ($user, $role): void {
            $user->roles()->sync([Role::forKey($role)->getKey()]);
        });

        $user->unsetRelation('roles');

        return $user;
    }
}
