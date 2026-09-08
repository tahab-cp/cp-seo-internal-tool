<?php

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_super_admin(): void
    {
        $this->artisan('app:create-super-admin', [
            '--name' => 'Root Admin',
            '--email' => 'root@example.com',
            '--password' => 'secret-password',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'root@example.com')->firstOrFail();

        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertTrue($user->hasRole(UserRole::SuperAdmin));
    }

    public function test_it_rejects_invalid_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('app:create-super-admin', [
            '--name' => 'Root Admin',
            '--email' => 'taken@example.com',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
