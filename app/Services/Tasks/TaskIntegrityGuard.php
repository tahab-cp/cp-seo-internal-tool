<?php

namespace App\Services\Tasks;

use App\Exceptions\TaskAssignmentException;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActiveUserGuard;
use App\Services\MonthlyCycles\Concerns\LocksMonthlyCycles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Domain rules every task workflow must respect, independent of any form:
 *
 * - a monthly cycle must belong to the task's project;
 * - locked cycles are immutable;
 * - assignees must be active and able to access the project.
 */
class TaskIntegrityGuard
{
    use LocksMonthlyCycles;

    public function __construct(
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * Resolve a cycle id to a cycle of *this* project (null for project-level).
     */
    public function resolveCycle(Project $project, int|string|null $cycleId): ?MonthlyCycle
    {
        if ($cycleId === null || $cycleId === '') {
            return null;
        }

        $cycle = MonthlyCycle::query()->find((int) $cycleId);

        if ($cycle === null || (int) $cycle->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Monthly cycle [%s] does not belong to project "%s".',
                $cycleId,
                $project->name,
            ));
        }

        return $cycle;
    }

    /**
     * Row-lock the cycle (inside the caller's transaction) and refuse if it
     * is locked. Project-level (null) needs no lock.
     */
    public function ensureCycleNotLocked(?MonthlyCycle $cycle, string $operation): void
    {
        $this->lockCycle($cycle, $operation);
    }

    public function ensureTaskNotLocked(Task $task, string $operation): void
    {
        $this->lockCycle($task->monthly_cycle_id, $operation);
    }

    /**
     * An assignee must be an active user who can access the project.
     */
    public function ensureAssignable(Project $project, int|string|null $userId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $this->activeUsers->ensureActive([$userId], 'task assignees');

        $user = User::query()->findOrFail((int) $userId);

        if (! $this->canAccess($user, $project)) {
            throw TaskAssignmentException::cannotAccessProject($user, $project);
        }
    }

    /**
     * Project access is the single source of truth (ProjectPolicy::view).
     */
    public function canAccess(User $user, Project $project): bool
    {
        return Gate::forUser($user)->allows('view', $project);
    }

    /**
     * Active users who may be assigned tasks on the project, by name.
     *
     * @return Collection<int, User>
     */
    public function assignableUsers(Project $project): Collection
    {
        return User::query()
            ->active()
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->canAccess($user, $project))
            ->values();
    }
}
