<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin')
            ->assertRedirect(Filament::getLoginUrl());
    }

    public function test_the_login_page_is_publicly_reachable(): void
    {
        $this->get(Filament::getLoginUrl())->assertOk();
    }

    public function test_active_users_with_a_role_can_access_the_panel(): void
    {
        $user = User::factory()->seoExecutive()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee($user->name);
    }

    public function test_every_active_role_can_access_the_panel(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
            User::factory()->seoExecutive()->create(),
        ] as $user) {
            $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        }
    }

    public function test_inactive_users_cannot_access_the_panel(): void
    {
        $user = User::factory()->superAdmin()->inactive()->create();

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_inactive_users_cannot_log_in(): void
    {
        $user = User::factory()->seoManager()->inactive()->create([
            'password' => 'secret-password',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'secret-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_users_without_a_role_cannot_access_the_panel(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_a_user_can_log_in_through_the_filament_login_form(): void
    {
        $user = User::factory()->seoExecutive()->create([
            'password' => 'secret-password',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'secret-password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->seoExecutive()->create();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }
}
