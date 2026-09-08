<?php

namespace App\Policies;

use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;

/**
 * Monthly cycle visibility follows project visibility. Cycles and their
 * target snapshots are created by the domain actions only; nobody edits
 * or deletes them through generic CRUD.
 */
class MonthlyCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, MonthlyCycle $cycle): bool
    {
        return $user->can('view', $cycle->project);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MonthlyCycle $cycle): bool
    {
        return false;
    }

    public function delete(User $user, MonthlyCycle $cycle): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
