<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;

/**
 * Ranking observations follow the keyword's project access and the cycle
 * lock. A locked cycle is read-only for everyone, Super Admin included,
 * until the formal unlock workflow (Milestone 14). History is never deleted.
 */
class RankingSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, RankingSnapshot $snapshot): bool
    {
        return $user->can('view', $snapshot->keyword->project);
    }

    /**
     * Creating happens inside a project; ProjectPolicy::recordRankings
     * guards the project and the actions guard the lock.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, RankingSnapshot $snapshot): bool
    {
        return $this->view($user, $snapshot) && ! $snapshot->isLocked();
    }

    public function delete(User $user, RankingSnapshot $snapshot): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
