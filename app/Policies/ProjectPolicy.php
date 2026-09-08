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
