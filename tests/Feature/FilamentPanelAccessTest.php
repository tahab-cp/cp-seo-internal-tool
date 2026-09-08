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

    public function test_authenticated_users_can_access_the_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee($user->name);
    }

    public function test_users_may_access_the_admin_panel(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_a_user_can_log_in_through_the_filament_login_form(): void
    {
        $user = User::factory()->create([
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
        $user = User::factory()->create();

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
