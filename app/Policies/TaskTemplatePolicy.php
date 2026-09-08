<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TaskTemplate;
use App\Models\User;

/**
 * Task templates are management configuration: Super Admin and SEO Manager.
 */
class TaskTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTaskTemplates);
    }

    public function view(User $user, TaskTemplate $template): bool
    {
        return $user->hasPermission(Permission::ManageTaskTemplates);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTaskTemplates);
    }

    public function update(User $user, TaskTemplate $template): bool
    {
        return $user->hasPermission(Permission::ManageTaskTemplates);
    }

    public function activate(User $user, TaskTemplate $template): bool
    {
        return $user->hasPermission(Permission::ManageTaskTemplates);
    }

    public function deactivate(User $user, TaskTemplate $template): bool
    {
        return $user->hasPermission(Permission::ManageTaskTemplates);
    }

    /**
     * Templates are deactivated, never deleted.
     */
    public function delete(User $user, TaskTemplate $template): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
