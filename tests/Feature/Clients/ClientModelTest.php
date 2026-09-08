<?php

namespace Tests\Feature\Clients;

use App\Actions\Clients\ArchiveClientAction;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_is_cast_to_the_enum_and_stored_as_a_string(): void
    {
        $client = Client::factory()->inactive()->create();

        $this->assertSame(ClientStatus::Inactive, $client->fresh()->status);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'status' => 'inactive']);
    }

    public function test_the_account_manager_relationship_works(): void
    {
        $manager = User::factory()->seoManager()->create();
        $client = Client::factory()->managedBy($manager)->create();

        $this->assertTrue($client->accountManager->is($manager));
        $this->assertSame($manager->id, $client->account_manager_id);

        $unmanaged = Client::factory()->create();

        $this->assertNull($unmanaged->accountManager);
    }

    public function test_the_archive_action_marks_the_client_archived_and_is_idempotent(): void
    {
        $client = Client::factory()->create();

        app(ArchiveClientAction::class)->handle($client);
        app(ArchiveClientAction::class)->handle($client);

        $this->assertSame(ClientStatus::Archived, $client->fresh()->status);
        $this->assertNotSoftDeleted($client);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_archived_clients_remain_in_normal_queries(): void
    {
        Client::factory()->archived()->create();

        $this->assertSame(1, Client::query()->count());
    }

    public function test_soft_deletes_work_only_when_explicitly_invoked(): void
    {
        $client = Client::factory()->create();

        $client->delete();

        $this->assertSoftDeleted($client);
        $this->assertSame(0, Client::query()->count());
        $this->assertSame(1, Client::withTrashed()->count());
        $this->assertTrue(Client::withTrashed()->find($client->id)->trashed());

        Client::withTrashed()->find($client->id)->restore();

        $this->assertNotSoftDeleted($client);
        $this->assertSame(1, Client::query()->count());
    }

    public function test_the_factory_produces_active_clients_by_default(): void
    {
        $client = Client::factory()->create();

        $this->assertSame(ClientStatus::Active, $client->status);
        $this->assertFalse($client->isArchived());
        $this->assertNull($client->deleted_at);
    }
}
