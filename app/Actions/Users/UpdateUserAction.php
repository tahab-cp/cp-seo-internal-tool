<?php

namespace App\Actions\Users;

use App\Models\User;

class UpdateUserAction
{
    /**
     * Update profile attributes. The password only changes when one is given.
     *
     * @param  array{name?: string, email?: string, password?: string|null}  $attributes
     */
    public function handle(User $user, array $attributes): User
    {
        $user->fill(array_filter([
            'name' => $attributes['name'] ?? null,
            'email' => $attributes['email'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        if (filled($attributes['password'] ?? null)) {
            $user->password = $attributes['password'];
        }

        $user->save();

        return $user;
    }
}
