<?php

namespace App\Actions\Users;

use App\Models\User;

class SetUserActiveStatusAction
{
    /**
     * Activate or deactivate a user. Deactivated users keep their history
     * but can no longer sign in or hold permissions.
     */
    public function handle(User $user, bool $isActive): User
    {
        $user->forceFill(['is_active' => $isActive])->save();

        return $user;
    }
}
