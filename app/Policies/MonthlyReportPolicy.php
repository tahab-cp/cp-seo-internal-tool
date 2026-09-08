<?php

namespace App\Policies;

use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;

/**
 * Reports follow project access. Anyone who can see the project may view
 * a report and its readiness; preparing (editing the draft) additionally
 * needs an unlocked month and a draft status. Review/finalisation arrive
 * in Milestone 13. Reports are never deleted.
 */
class MonthlyReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, MonthlyReport $report): bool
    {
        return $user->can('view', $report->monthlyCycle->project);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Edit draft content (executive summary for now).
     */
    public function prepare(User $user, MonthlyReport $report): bool
    {
        return $this->view($user, $report) && ! $report->isLocked() && $report->isDraft();
    }

    public function update(User $user, MonthlyReport $report): bool
    {
        return $this->prepare($user, $report);
    }

    public function delete(User $user, MonthlyReport $report): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
