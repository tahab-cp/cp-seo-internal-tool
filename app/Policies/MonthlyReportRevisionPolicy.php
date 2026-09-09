<?php

namespace App\Policies;

use App\Models\MonthlyReportRevision;
use App\Models\Project;
use App\Models\User;

/**
 * Archived finals are read-only evidence: visible to whoever can see the
 * project, never created by hand, updated, deleted or restored.
 */
class MonthlyReportRevisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, MonthlyReportRevision $revision): bool
    {
        return $user->can('view', $revision->report);
    }

    public function downloadPdf(User $user, MonthlyReportRevision $revision): bool
    {
        return $this->view($user, $revision) && $revision->hasPdf();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MonthlyReportRevision $revision): bool
    {
        return false;
    }

    public function delete(User $user, MonthlyReportRevision $revision): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, MonthlyReportRevision $revision): bool
    {
        return false;
    }

    public function forceDelete(User $user, MonthlyReportRevision $revision): bool
    {
        return false;
    }
}
