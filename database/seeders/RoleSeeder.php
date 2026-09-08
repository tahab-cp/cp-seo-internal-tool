<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Ensure every system role exists. Safe to run repeatedly.
     */
    public function run(): void
    {
        foreach (UserRole::cases() as $role) {
            Role::forKey($role);
        }
    }
}
