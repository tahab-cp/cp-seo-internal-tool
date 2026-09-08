<?php

namespace Tests\Feature\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Access to user administration is checked through direct URLs and Livewire
 * page mounts, proving the policy is enforced server-side rather than only
 * by hidden navigation.
 */
class UserManagementAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_user_management(): void
    {
        $this->get(UserResource::getUrl('index'))
            ->assertRedirect(Filament::getLoginUrl());
    }

    public function test_super_admin_can_access_user_management(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $other = User::factory()->seoExecutive()->create();

        $this->actingAs($admin);

        $this->get(UserResource::getUrl('index'))->assertOk();
        $this->get(UserResource::getUrl('create'))->assertOk();
        $this->get(UserResource::getUrl('edit', ['record' => $other]))->assertOk();

        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(UserResource::canCreate());
        $this->assertTrue(UserResource::canEdit($other));
    }

    public function test_super_admin_sees_users_in_navigation(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/admin')
            ->assertOk()
            ->assertSee(UserResource::getUrl('index'));
    }

    public function test_seo_manager_cannot_access_user_management(): void
    {
        $manager = User::factory()->seoManager()->create();
        $other = User::factory()->seoExecutive()->create();

        $this->actingAs($manager);

        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        $this->get(UserResource::getUrl('edit', ['record' => $other]))->assertForbidden();

        Livewire::test(ListUsers::class)->assertForbidden();
        Livewire::test(CreateUser::class)->assertForbidden();
        Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])->assertForbidden();

        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(UserResource::canCreate());
        $this->assertFalse(UserResource::canEdit($other));
        $this->assertFalse(UserResource::canAccess());
    }

    public function test_seo_manager_does_not_see_users_in_navigation(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        $this->get('/admin')
            ->assertOk()
            ->assertDontSee(UserResource::getUrl('index'));
    }

    public function test_seo_executive_cannot_access_user_management(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $other = User::factory()->seoExecutive()->create();

        $this->actingAs($executive);

        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        $this->get(UserResource::getUrl('edit', ['record' => $other]))->assertForbidden();

        Livewire::test(ListUsers::class)->assertForbidden();
        Livewire::test(CreateUser::class)->assertForbidden();
        Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])->assertForbidden();

        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(UserResource::canAccess());
    }

    public function test_seo_executive_does_not_see_users_in_navigation(): void
    {
        $this->actingAs(User::factory()->seoExecutive()->create());

        $this->get('/admin')
            ->assertOk()
            ->assertDontSee(UserResource::getUrl('index'));
    }

    public function test_inactive_super_admin_cannot_access_user_management(): void
    {
        $this->actingAs(User::factory()->superAdmin()->inactive()->create());

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }
}
