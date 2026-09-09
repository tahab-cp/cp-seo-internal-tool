<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;

/**
 * Reports follow project access. Anyone who can see the project may view
 * a report, its readiness, its preview and (once final) its PDF, and may
 * prepare it (edit narrative, mark ready) while it is not final and the
 * month is unlocked. Finalizing and internal review notes need the
 * reports.finalize permission (Super Admin, SEO Manager). Final reports
 * are immutable until the Milestone 14 unlock workflow. Never deleted.
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
     * Edit narrative (executive summary, section commentary) on a Draft or
     * Ready for Review report in an unlocked month.
     */
    public function prepare(User $user, MonthlyReport $report): bool
    {
        return $this->view($user, $report) && $report->isEditable();
    }

    public function update(User $user, MonthlyReport $report): bool
    {
        return $this->prepare($user, $report);
    }

    /**
     * Move a complete report to Ready for Review (readiness is enforced by
     * MarkReportReadyAction, never here).
     */
    public function markReady(User $user, MonthlyReport $report): bool
    {
        return $this->prepare($user, $report);
    }

    /**
     * Internal reviewer notes: reviewers only, never on a final report.
     */
    public function reviewNotes(User $user, MonthlyReport $report): bool
    {
        return $user->hasPermission(Permission::FinalizeReports) && $this->prepare($user, $report);
    }

    /**
     * Finalize a Ready for Review report (Super Admin / SEO Manager).
     * Readiness is re-checked by FinalizeMonthlyReportAction.
     */
    public function finalize(User $user, MonthlyReport $report): bool
    {
        return $user->hasPermission(Permission::FinalizeReports)
            && $this->view($user, $report)
            && $report->isReadyForReview()
            && ! $report->isLocked();
    }

    /**
     * Download the stored final PDF.
     */
    public function downloadPdf(User $user, MonthlyReport $report): bool
    {
        return $this->view($user, $report) && $report->isFinal() && $report->hasPdf();
    }

    /**
     * Unlock a final report's month for correction: Super Admin only
     * (reports.unlock). The current final is archived as a revision first.
     */
    public function unlock(User $user, MonthlyReport $report): bool
    {
        return $user->hasPermission(Permission::UnlockReports)
            && $this->view($user, $report)
            && $report->isFinal()
            && $report->isLocked();
    }

    /**
     * Archived revisions and audit history follow report visibility.
     */
    public function viewHistory(User $user, MonthlyReport $report): bool
    {
        return $this->view($user, $report);
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
