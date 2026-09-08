<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;

/**
 * Global client administration. Super Admins and SEO Managers manage all
 * clients; SEO Executives have no access here and reach client context
 * only through their assigned projects (Milestone 3).
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewClients);
    }

    public function view(User $user, Client $client): bool
    {
        return $user->hasPermission(Permission::ViewClients);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CreateClients);
    }

    public function update(User $user, Client $client): bool
    {
        return $user->hasPermission(Permission::UpdateClients);
    }

    public function archive(User $user, Client $client): bool
    {
        return $user->hasPermission(Permission::ArchiveClients);
    }

    /**
     * Clients are archived, never deleted, through the normal UI. Soft
     * deletes are reserved for explicit administrative use later.
     */
    public function delete(User $user, Client $client): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Client $client): bool
    {
        return false;
    }

    public function forceDelete(User $user, Client $client): bool
    {
        return false;
    }
}
