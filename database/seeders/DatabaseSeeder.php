<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only system roles are seeded. Bootstrap the first administrator with
     * `php artisan app:create-super-admin`.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
}
