<?php

namespace App\Policies;

use App\Models\MonthlyNote;
use App\Models\Project;
use App\Models\User;

/**
 * Notes follow project access and the cycle lock. A locked cycle is
 * read-only for everyone, Super Admin included, until Milestone 14.
 */
class MonthlyNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, MonthlyNote $note): bool
    {
        return $user->can('view', $note->monthlyCycle->project);
    }

    /**
     * Creating happens inside a cycle; MonthlyCyclePolicy::manageNotes
     * guards the cycle and CreateMonthlyNoteAction guards the lock.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, MonthlyNote $note): bool
    {
        return $this->view($user, $note) && ! $note->isLocked();
    }

    public function delete(User $user, MonthlyNote $note): bool
    {
        return $this->update($user, $note);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
