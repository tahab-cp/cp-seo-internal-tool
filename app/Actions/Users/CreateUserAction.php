<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateUserAction
{
    public function __construct(
        protected AssignUserRoleAction $assignUserRole,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, is_active?: bool}  $attributes
     */
    public function handle(array $attributes, UserRole $role): User
    {
        return DB::transaction(function () use ($attributes, $role): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'is_active' => $attributes['is_active'] ?? true,
                // Internal users are created by administrators; there is no
                // self-service verification flow.
                'email_verified_at' => now(),
            ]);

            return $this->assignUserRole->handle($user, $role);
        });
    }
}
