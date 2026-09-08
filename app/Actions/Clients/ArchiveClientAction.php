<?php

namespace App\Actions\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;

class ArchiveClientAction
{
    /**
     * Mark the client as archived. The record is kept, including its
     * history; this never soft-deletes or hard-deletes the client.
     */
    public function handle(Client $client): Client
    {
        if ($client->isArchived()) {
            return $client;
        }

        $client->forceFill(['status' => ClientStatus::Archived])->save();

        return $client;
    }
}
