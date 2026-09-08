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

    /**
     * Monthly analytics (GSC, GA4, authority) may be entered or replaced
     * by anyone who can see the project, while the cycle is unlocked. A
     * locked cycle is read-only for everyone, Super Admin included, until
     * the formal unlock workflow (Milestone 14).
     */
    public function manageAnalytics(User $user, MonthlyCycle $cycle): bool
    {
        return $this->view($user, $cycle) && ! $cycle->isLocked();
    }

    /**
     * Capture or edit narrative notes for the month while it is unlocked.
     */
    public function manageNotes(User $user, MonthlyCycle $cycle): bool
    {
        return $this->view($user, $cycle) && ! $cycle->isLocked();
    }

    /**
     * Start (ensure) the month's draft report. Executives prepare the
     * reports for their own projects; the project template is untouched.
     */
    public function ensureReport(User $user, MonthlyCycle $cycle): bool
    {
        return $this->view($user, $cycle) && ! $cycle->isLocked();
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
