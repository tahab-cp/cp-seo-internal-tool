<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;

/**
 * Page access follows project access. Pages are project master data, so a
 * locked historical cycle never makes them immutable. Nobody deletes pages
 * through normal workflows (mark them removed instead).
 */
class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, Page $page): bool
    {
        return $user->can('view', $page->project);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Page $page): bool
    {
        return $this->view($user, $page);
    }

    public function setStatus(User $user, Page $page): bool
    {
        return $this->view($user, $page);
    }

    public function delete(User $user, Page $page): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Page $page): bool
    {
        return false;
    }

    public function forceDelete(User $user, Page $page): bool
    {
        return false;
    }
}
