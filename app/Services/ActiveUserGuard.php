<?php

namespace App\Services;

use App\Exceptions\InactiveUserAssignmentException;
use App\Models\User;

/**
 * Domain-level protection for the invariant "assigned users must be active".
 *
 * Filament forms validate the same rule at the request boundary; this guard
 * makes the Actions safe for every other caller (imports, commands, seeders).
 */
class ActiveUserGuard
{
    /**
     * @param  list<int|string|null>  $userIds
     *
     * @throws InactiveUserAssignmentException
     */
    public function ensureActive(array $userIds, string $role): void
    {
        $ids = collect($userIds)
            ->filter(fn (int|string|null $id): bool => $id !== null && $id !== '')
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $active = User::query()->active()->whereKey($ids)->pluck('id');

        $rejected = $ids->diff($active)->values()->all();

        if ($rejected !== []) {
            throw InactiveUserAssignmentException::for($role, $rejected);
        }
    }
}
