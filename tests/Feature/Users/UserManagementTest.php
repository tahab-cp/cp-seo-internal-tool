<?php

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);
    }

    public function test_super_admin_can_list_users(): void
    {
        $users = User::factory()->count(3)->seoExecutive()->create();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords($users)
            ->assertCanSeeTableRecords([$this->admin]);
    }

    public function test_super_admin_can_create_a_user_with_a_role(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Jordan Rivers',
                'email' => 'jordan@example.com',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'role' => UserRole::SeoManager->value,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'jordan@example.com')->firstOrFail();

        $this->assertSame('Jordan Rivers', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertTrue($user->hasRole(UserRole::SeoManager));
    }

    public function test_creating_a_user_validates_required_fields(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => '',
                'email' => 'not-an-email',
                'password' => 'short',
                'password_confirmation' => 'different',
                'role' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'required',
                'email' => 'email',
                'password',
                'role' => 'required',
            ]);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_creating_a_user_rejects_duplicate_emails(): void
    {
        $existing = User::factory()->seoExecutive()->create();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Duplicate',
                'email' => $existing->email,
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'role' => UserRole::SeoExecutive->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);
    }

    public function test_super_admin_can_edit_a_user_and_change_their_role(): void
    {
        $user = User::factory()->seoExecutive()->create();

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertFormSet([
                'name' => $user->name,
                'role' => UserRole::SeoExecutive,
                'is_active' => true,
            ])
            ->fillForm([
                'name' => 'Renamed User',
                'role' => UserRole::SeoManager->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Renamed User', $user->name);
        $this->assertTrue($user->hasRole(UserRole::SeoManager));
        $this->assertCount(1, $user->roles);
    }

    public function test_editing_without_a_password_keeps_the_existing_password(): void
    {
        $user = User::factory()->seoExecutive()->create(['password' => 'original-password']);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['password' => '', 'password_confirmation' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('original-password', $user->refresh()->password));
    }

    public function test_editing_can_deactivate_a_user_through_the_form(): void
    {
        $user = User::factory()->seoExecutive()->create();

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($user->refresh()->is_active);
    }

    public function test_super_admin_can_deactivate_and_activate_a_user_from_the_list(): void
    {
        $user = User::factory()->seoExecutive()->create();

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('deactivate', $user)
            ->assertTableActionHidden('activate', $user)
            ->callTableAction('deactivate', $user)
            ->assertNotified('User deactivated');

        $this->assertFalse($user->refresh()->is_active);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('deactivate', $user)
            ->assertTableActionVisible('activate', $user)
            ->callTableAction('activate', $user)
            ->assertNotified('User activated');

        $this->assertTrue($user->refresh()->is_active);
    }

    public function test_super_admin_cannot_deactivate_themselves(): void
    {
        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('deactivate', $this->admin);

        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
            ->assertFormFieldDisabled('is_active')
            ->assertFormFieldDisabled('role')
            ->fillForm(['is_active' => false, 'role' => UserRole::SeoExecutive->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->admin->refresh();

        $this->assertTrue($this->admin->is_active);
        $this->assertTrue($this->admin->isSuperAdmin());
    }

    public function test_there_is_no_delete_action_for_users(): void
    {
        $user = User::factory()->seoExecutive()->create();

        Livewire::test(ListUsers::class)
            ->assertTableActionDoesNotExist('delete', record: $user);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }
}
