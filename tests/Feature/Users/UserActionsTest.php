<?php

namespace Tests\Feature\Users;

use App\Actions\Users\AssignUserRoleAction;
use App\Actions\Users\CreateUserAction;
use App\Actions\Users\SetUserActiveStatusAction;
use App\Actions\Users\UpdateUserAction;
use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_user_action_creates_an_active_verified_user_with_a_role(): void
    {
        $user = app(CreateUserAction::class)->handle([
            'name' => 'Sam Lee',
            'email' => 'sam@example.com',
            'password' => 'secret-password',
        ], UserRole::SeoExecutive);

        $this->assertTrue($user->exists);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertTrue($user->hasRole(UserRole::SeoExecutive));
    }

    public function test_assign_role_action_replaces_the_existing_role(): void
    {
        $user = User::factory()->seoExecutive()->create();

        app(AssignUserRoleAction::class)->handle($user, UserRole::SeoManager);

        $this->assertTrue($user->hasRole(UserRole::SeoManager));
        $this->assertFalse($user->hasRole(UserRole::SeoExecutive));
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $user->id,
            'role_id' => Role::forKey(UserRole::SeoExecutive)->id,
        ]);
    }

    public function test_a_user_always_ends_with_exactly_one_role(): void
    {
        $user = User::factory()->seoExecutive()->create();

        foreach ([UserRole::SeoManager, UserRole::SuperAdmin, UserRole::SeoExecutive] as $role) {
            app(AssignUserRoleAction::class)->handle($user, $role);

            $this->assertCount(1, $user->fresh()->roles);
            $this->assertSame($role, $user->fresh()->role());
        }

        $this->assertSame(1, DB::table('role_user')->where('user_id', $user->id)->count());
    }

    public function test_the_database_rejects_a_second_role_for_the_same_user(): void
    {
        $user = User::factory()->seoExecutive()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $user->roles()->attach(Role::forKey(UserRole::SeoManager));
    }

    public function test_assigning_the_same_role_again_is_idempotent(): void
    {
        $user = User::factory()->seoManager()->create();

        app(AssignUserRoleAction::class)->handle($user, UserRole::SeoManager);

        $this->assertCount(1, $user->fresh()->roles);
        $this->assertTrue($user->fresh()->hasRole(UserRole::SeoManager));
    }

    public function test_update_user_action_only_changes_the_password_when_given(): void
    {
        $user = User::factory()->seoExecutive()->create(['password' => 'original-password']);

        app(UpdateUserAction::class)->handle($user, ['name' => 'New Name', 'password' => null]);
        $this->assertSame('New Name', $user->refresh()->name);
        $this->assertTrue(Hash::check('original-password', $user->password));

        app(UpdateUserAction::class)->handle($user, ['password' => 'changed-password']);
        $this->assertTrue(Hash::check('changed-password', $user->refresh()->password));
    }

    public function test_set_active_status_action_toggles_activation(): void
    {
        $user = User::factory()->seoExecutive()->create();

        app(SetUserActiveStatusAction::class)->handle($user, false);
        $this->assertFalse($user->refresh()->is_active);

        app(SetUserActiveStatusAction::class)->handle($user, true);
        $this->assertTrue($user->refresh()->is_active);
    }
}
