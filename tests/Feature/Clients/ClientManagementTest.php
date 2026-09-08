<?php

namespace Tests\Feature\Clients;

use App\Enums\ClientStatus;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_a_client(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->createClientThroughForm('Casa Botanica');

        $this->assertDatabaseHas('clients', ['name' => 'Casa Botanica', 'status' => 'active']);
    }

    public function test_seo_manager_can_create_a_client(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        $this->createClientThroughForm('Little Astronauts');

        $this->assertDatabaseHas('clients', ['name' => 'Little Astronauts', 'status' => 'active']);
    }

    public function test_creating_a_client_stores_every_field(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $manager = User::factory()->seoManager()->create();

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Afzal',
                'status' => ClientStatus::Inactive->value,
                'company_name' => 'Afzal Holdings',
                'contact_name' => 'Afzal Khan',
                'email' => 'afzal@example.com',
                'phone' => '+44 20 7946 0000',
                'account_manager_id' => $manager->id,
                'notes' => 'Prefers monthly calls.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::query()->where('name', 'Afzal')->firstOrFail();

        $this->assertSame(ClientStatus::Inactive, $client->status);
        $this->assertSame('Afzal Holdings', $client->company_name);
        $this->assertSame('Afzal Khan', $client->contact_name);
        $this->assertSame('afzal@example.com', $client->email);
        $this->assertSame('+44 20 7946 0000', $client->phone);
        $this->assertSame('Prefers monthly calls.', $client->notes);
        $this->assertTrue($client->accountManager->is($manager));
        $this->assertNull($client->deleted_at);
    }

    public function test_client_validation_requires_name_and_status_and_a_valid_email(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => '',
                'status' => null,
                'email' => 'not-an-email',
                'company_name' => str_repeat('x', 256),
                'contact_name' => str_repeat('x', 256),
                'phone' => str_repeat('1', 51),
            ])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'required',
                'status' => 'required',
                'email' => 'email',
                'company_name' => 'max',
                'contact_name' => 'max',
                'phone' => 'max',
            ]);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_client_validation_rejects_an_inactive_or_unknown_account_manager(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $inactive = User::factory()->seoManager()->inactive()->create();

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Client',
                'status' => ClientStatus::Active->value,
                'account_manager_id' => $inactive->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['account_manager_id']);

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Client',
                'status' => ClientStatus::Active->value,
                'account_manager_id' => 999999,
            ])
            ->call('create')
            ->assertHasFormErrors(['account_manager_id']);

        $this->assertDatabaseCount('clients', 0);
    }

    public function test_account_manager_is_optional(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Unmanaged Client',
                'status' => ClientStatus::Active->value,
                'account_manager_id' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('clients', ['name' => 'Unmanaged Client', 'account_manager_id' => null]);
    }

    public function test_a_client_can_be_edited(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $client = Client::factory()->create(['name' => 'Old Name']);
        $manager = User::factory()->seoManager()->create();

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertFormSet(['name' => 'Old Name', 'status' => ClientStatus::Active])
            ->fillForm([
                'name' => 'New Name',
                'status' => ClientStatus::Inactive->value,
                'account_manager_id' => $manager->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();

        $this->assertSame('New Name', $client->name);
        $this->assertSame(ClientStatus::Inactive, $client->status);
        $this->assertTrue($client->accountManager->is($manager));
    }

    public function test_the_list_shows_clients_and_supports_search_and_filters(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $manager = User::factory()->seoManager()->create();

        $alpha = Client::factory()->managedBy($manager)->create([
            'name' => 'Alpha',
            'company_name' => 'Alpha Co',
            'contact_name' => 'Alice Example',
            'email' => 'alice@alpha.test',
        ]);
        $beta = Client::factory()->archived()->create([
            'name' => 'Beta',
            'company_name' => 'Beta Ltd',
            'contact_name' => 'Bob Example',
            'email' => 'bob@beta.test',
        ]);

        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$alpha, $beta])
            ->searchTable('Alpha Co')
            ->assertCanSeeTableRecords([$alpha])
            ->assertCanNotSeeTableRecords([$beta])
            ->searchTable('bob@beta')
            ->assertCanSeeTableRecords([$beta])
            ->assertCanNotSeeTableRecords([$alpha])
            ->searchTable('Alice')
            ->assertCanSeeTableRecords([$alpha])
            ->searchTable('')
            ->filterTable('status', ClientStatus::Archived->value)
            ->assertCanSeeTableRecords([$beta])
            ->assertCanNotSeeTableRecords([$alpha])
            ->resetTableFilters()
            ->filterTable('account_manager_id', $manager->id)
            ->assertCanSeeTableRecords([$alpha])
            ->assertCanNotSeeTableRecords([$beta]);
    }

    public function test_the_list_explains_clients_when_empty(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ListClients::class)
            ->assertSee('No clients yet')
            ->assertSee('Clients are agency accounts');
    }

    public function test_archive_action_sets_status_to_archived_without_deleting(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());
        $client = Client::factory()->create();

        Livewire::test(ListClients::class)
            ->assertTableActionVisible('archive', $client)
            ->callTableAction('archive', $client)
            ->assertNotified('Client archived');

        $client->refresh();

        $this->assertSame(ClientStatus::Archived, $client->status);
        $this->assertNull($client->deleted_at);
        $this->assertNotSoftDeleted($client);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'status' => 'archived', 'deleted_at' => null]);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_archive_action_is_hidden_for_already_archived_clients(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $client = Client::factory()->archived()->create();

        Livewire::test(ListClients::class)
            ->assertTableActionHidden('archive', $client);

        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('archive');
    }

    public function test_archive_is_available_from_the_view_and_edit_pages(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $fromView = Client::factory()->create();
        Livewire::test(ViewClient::class, ['record' => $fromView->getRouteKey()])
            ->callAction('archive')
            ->assertNotified('Client archived');
        $this->assertSame(ClientStatus::Archived, $fromView->fresh()->status);
        $this->assertNotSoftDeleted($fromView);

        $fromEdit = Client::factory()->create();
        Livewire::test(EditClient::class, ['record' => $fromEdit->getRouteKey()])
            ->callAction('archive')
            ->assertNotified('Client archived');
        $this->assertSame(ClientStatus::Archived, $fromEdit->fresh()->status);
        $this->assertNotSoftDeleted($fromEdit);
    }

    public function test_there_is_no_delete_action_anywhere_in_the_client_ui(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $client = Client::factory()->create();

        Livewire::test(ListClients::class)
            ->assertTableActionDoesNotExist('delete', record: $client)
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])
            ->assertActionDoesNotExist('delete');

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->assertActionDoesNotExist('delete');
    }

    protected function createClientThroughForm(string $name): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => $name,
                'status' => ClientStatus::Active->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }
}
