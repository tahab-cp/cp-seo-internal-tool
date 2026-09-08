<?php

namespace App\Policies;

use App\Models\Backlink;
use App\Models\Project;
use App\Models\User;

/**
 * Backlinks follow project access and the cycle lock. A locked cycle is
 * read-only for everyone, Super Admin included, until the formal unlock
 * workflow (Milestone 14). History is retired via status, never deleted.
 */
class BacklinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, Backlink $backlink): bool
    {
        return $user->can('view', $backlink->project);
    }

    /**
     * Creating happens inside a project; ProjectPolicy::manageBacklinks
     * guards the project and CreateBacklinkAction guards the lock.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Backlink $backlink): bool
    {
        return $this->view($user, $backlink) && ! $backlink->isLocked();
    }

    public function setStatus(User $user, Backlink $backlink): bool
    {
        return $this->update($user, $backlink);
    }

    public function delete(User $user, Backlink $backlink): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Backlink $backlink): bool
    {
        return false;
    }

    public function forceDelete(User $user, Backlink $backlink): bool
    {
        return false;
    }
}
