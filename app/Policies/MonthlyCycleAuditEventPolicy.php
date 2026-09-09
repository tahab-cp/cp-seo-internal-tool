<?php

namespace App\Policies;

use App\Models\MonthlyCycleAuditEvent;
use App\Models\Project;
use App\Models\User;

/**
 * Audit events are immutable history: readable with the project, never
 * created by hand, updated or deleted.
 */
class MonthlyCycleAuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, MonthlyCycleAuditEvent $event): bool
    {
        return $user->can('view', $event->monthlyCycle->project);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MonthlyCycleAuditEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, MonthlyCycleAuditEvent $event): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, MonthlyCycleAuditEvent $event): bool
    {
        return false;
    }

    public function forceDelete(User $user, MonthlyCycleAuditEvent $event): bool
    {
        return false;
    }
}
