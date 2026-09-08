<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * Task access follows project access: whoever can view the project can
 * view and work its tasks. Monthly tasks in a locked cycle are read-only
 * for everyone; nobody deletes tasks (cancel them instead).
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->can('view', $task->project);
    }

    /**
     * Creating happens inside a project; ProjectPolicy::manageTasks guards
     * the specific project and CreateTaskAction guards the cycle lock.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task) && ! $task->isLocked();
    }

    public function setStatus(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Task $task): bool
    {
        return false;
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return false;
    }
}
