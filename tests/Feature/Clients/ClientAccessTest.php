<?php

namespace Tests\Feature\Clients;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Client administration access is checked through direct URLs and Livewire
 * page mounts, proving the policy is enforced server-side.
 */
class ClientAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_client_administration(): void
    {
        $this->get(ClientResource::getUrl('index'))
            ->assertRedirect(Filament::getLoginUrl());
    }

    public function test_super_admin_can_access_every_client_page(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertCanAccessAllClientPages(Client::factory()->create());
    }

    public function test_seo_manager_can_access_every_client_page(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        $this->assertCanAccessAllClientPages(Client::factory()->create());
    }

    public function test_super_admin_and_seo_manager_see_clients_in_navigation(): void
    {
        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->actingAs($user)
                ->get('/admin')
                ->assertOk()
                ->assertSee(ClientResource::getUrl('index'));
        }
    }

    public function test_seo_executive_cannot_access_client_administration(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $client = Client::factory()->create();

        $this->actingAs($executive);

        $this->get(ClientResource::getUrl('index'))->assertForbidden();
        $this->get(ClientResource::getUrl('create'))->assertForbidden();
        $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertForbidden();
        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertForbidden();

        Livewire::test(ListClients::class)->assertForbidden();
        Livewire::test(CreateClient::class)->assertForbidden();
        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])->assertForbidden();
        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])->assertForbidden();

        $this->assertFalse(ClientResource::canViewAny());
        $this->assertFalse(ClientResource::canCreate());
        $this->assertFalse(ClientResource::canView($client));
        $this->assertFalse(ClientResource::canEdit($client));
        $this->assertFalse(ClientResource::canAccess());
    }

    public function test_seo_executive_does_not_see_clients_in_navigation(): void
    {
        $this->actingAs(User::factory()->seoExecutive()->create());

        $this->get('/admin')
            ->assertOk()
            ->assertDontSee(ClientResource::getUrl('index'));
    }

    public function test_seo_executive_cannot_archive_a_client_through_a_direct_action_call(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $client = Client::factory()->create();

        $this->actingAs($executive);

        Livewire::test(ListClients::class)->assertForbidden();
        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])->assertForbidden();

        $this->assertFalse($executive->can('archive', $client));
        $this->assertFalse($client->fresh()->isArchived());
    }

    public function test_inactive_manager_cannot_access_client_administration(): void
    {
        $this->actingAs(User::factory()->seoManager()->inactive()->create());

        $this->get(ClientResource::getUrl('index'))->assertForbidden();
    }

    protected function assertCanAccessAllClientPages(Client $client): void
    {
        $this->get(ClientResource::getUrl('index'))->assertOk();
        $this->get(ClientResource::getUrl('create'))->assertOk();
        $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk()->assertSee($client->name);
        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertOk();

        $this->assertTrue(ClientResource::canViewAny());
        $this->assertTrue(ClientResource::canCreate());
        $this->assertTrue(ClientResource::canView($client));
        $this->assertTrue(ClientResource::canEdit($client));
    }
}
