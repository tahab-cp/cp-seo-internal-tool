<?php

namespace Tests\Feature\Clients;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<Permission>
     */
    protected function clientPermissions(): array
    {
        return [
            Permission::ViewClients,
            Permission::CreateClients,
            Permission::UpdateClients,
            Permission::ArchiveClients,
        ];
    }

    public function test_super_admin_and_seo_manager_hold_every_client_ability(): void
    {
        $client = Client::factory()->create();

        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
        ] as $user) {
            $this->assertTrue($user->can('viewAny', Client::class));
            $this->assertTrue($user->can('view', $client));
            $this->assertTrue($user->can('create', Client::class));
            $this->assertTrue($user->can('update', $client));
            $this->assertTrue($user->can('archive', $client));

            foreach ($this->clientPermissions() as $permission) {
                $this->assertTrue($user->can($permission->value), $permission->value);
            }
        }
    }

    public function test_seo_executive_holds_no_client_ability(): void
    {
        $executive = User::factory()->seoExecutive()->create();
        $client = Client::factory()->create();

        $this->assertFalse($executive->can('viewAny', Client::class));
        $this->assertFalse($executive->can('view', $client));
        $this->assertFalse($executive->can('create', Client::class));
        $this->assertFalse($executive->can('update', $client));
        $this->assertFalse($executive->can('archive', $client));

        foreach ($this->clientPermissions() as $permission) {
            $this->assertFalse($executive->can($permission->value), $permission->value);
        }
    }

    public function test_nobody_may_delete_restore_or_force_delete_clients_through_the_policy(): void
    {
        $client = Client::factory()->create();

        foreach ([
            User::factory()->superAdmin()->create(),
            User::factory()->seoManager()->create(),
            User::factory()->seoExecutive()->create(),
        ] as $user) {
            $this->assertFalse($user->can('delete', $client));
            $this->assertFalse($user->can('deleteAny', Client::class));
            $this->assertFalse($user->can('restore', $client));
            $this->assertFalse($user->can('forceDelete', $client));
        }
    }

    public function test_inactive_users_lose_client_abilities(): void
    {
        $manager = User::factory()->seoManager()->inactive()->create();
        $client = Client::factory()->create();

        $this->assertFalse($manager->can('viewAny', Client::class));
        $this->assertFalse($manager->can('archive', $client));
    }
}
