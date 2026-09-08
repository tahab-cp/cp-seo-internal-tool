<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;

/**
 * Super Admins and SEO Managers manage every project. SEO Executives may
 * only view projects they own or belong to; they never create, edit,
 * archive or assign team members.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewAllProjects)
            || $user->hasPermission(Permission::ViewAssignedProjects);
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->hasPermission(Permission::ViewAllProjects)) {
            return true;
        }

        return $user->hasPermission(Permission::ViewAssignedProjects)
            && $project->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::CreateProjects);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::UpdateProjects);
    }

    public function assignTeam(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::AssignProjectTeam);
    }

    public function assignPackage(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::AssignProjectPackage);
    }

    public function manageTargets(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ManageProjectTargets);
    }

    /**
     * Manually create a missing monthly cycle for this project.
     */
    public function ensureMonthlyCycle(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::EnsureMonthlyCycles) && $this->view($user, $project);
    }

    /**
     * Anyone who can see a project may work its tasks (create, edit, status).
     * Locked monthly cycles are enforced by TaskPolicy and the task actions.
     */
    public function manageTasks(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    /**
     * Anyone who can see a project may maintain its pages and record
     * optimisation work. Locked cycles are enforced by
     * PageOptimizationPolicy and the page actions.
     */
    public function managePages(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    /**
     * Anyone who can see a project may maintain its keywords.
     */
    public function manageKeywords(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    /**
     * Anyone who can see a project may record rankings; locked cycles are
     * enforced by RankingSnapshotPolicy and the ranking actions.
     */
    public function recordRankings(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    /**
     * Anyone who can see a project may record its link-building work;
     * locked cycles are enforced by BacklinkPolicy and the backlink actions.
     */
    public function manageBacklinks(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    /**
     * Anyone who can see a project may plan and track its content; locked
     * cycles are enforced by ContentItemPolicy and the content actions.
     */
    public function manageContent(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    /**
     * Generate onboarding tasks from a task template (Admin / Manager).
     * Callable with the class name (no project) to gate the create form.
     */
    public function generateOnboarding(User $user, ?Project $project = null): bool
    {
        if (! $user->hasPermission(Permission::GenerateOnboardingTasks)) {
            return false;
        }

        return $project === null || $this->view($user, $project);
    }

    /**
     * Archiving soft-deletes the project so its history survives.
     */
    public function archive(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ArchiveProjects);
    }

    public function restore(User $user, Project $project): bool
    {
        return $user->hasPermission(Permission::ArchiveProjects);
    }

    /**
     * Filament's generic delete actions are never permitted; use archive.
     */
    public function delete(User $user, Project $project): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
