<?php

namespace App\Policies;

use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;

/**
 * Optimisation events follow project access and the cycle lock. A locked
 * cycle is read-only for everyone, Super Admin included, until the formal
 * unlock workflow (Milestone 14). Historical events are never deleted.
 */
class PageOptimizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, PageOptimization $optimization): bool
    {
        return $user->can('view', $optimization->project);
    }

    /**
     * Creating happens inside a project page; ProjectPolicy::managePages
     * guards the project and RecordPageOptimizationAction guards the lock.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PageOptimization $optimization): bool
    {
        return $this->view($user, $optimization) && ! $optimization->isLocked();
    }

    public function delete(User $user, PageOptimization $optimization): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
