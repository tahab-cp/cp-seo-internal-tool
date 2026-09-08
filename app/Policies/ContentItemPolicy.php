<?php

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;

/**
 * Content follows project access and the cycle lock. A locked cycle is
 * read-only for everyone, Super Admin included, until the formal unlock
 * workflow (Milestone 14). Obsolete items are cancelled, never deleted.
 */
class ContentItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, ContentItem $item): bool
    {
        return $user->can('view', $item->project);
    }

    /**
     * Creating happens inside a project; ProjectPolicy::manageContent
     * guards the project and CreateContentItemAction guards the lock.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ContentItem $item): bool
    {
        return $this->view($user, $item) && ! $item->isLocked();
    }

    public function setStatus(User $user, ContentItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function delete(User $user, ContentItem $item): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ContentItem $item): bool
    {
        return false;
    }

    public function forceDelete(User $user, ContentItem $item): bool
    {
        return false;
    }
}
