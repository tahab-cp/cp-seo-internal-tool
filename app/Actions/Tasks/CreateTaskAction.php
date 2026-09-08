<?php

namespace App\Actions\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskIntegrityGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateTaskAction
{
    public function __construct(
        protected TaskIntegrityGuard $guard,
    ) {}

    /**
     * Create a project-level task (monthly_cycle_id null) or a monthly task.
     *
     * @param  array<string, mixed>  $attributes  title, description, category, status, priority, due_date, monthly_cycle_id, assigned_user_id
     */
    public function handle(Project $project, array $attributes, User $creator): Task
    {
        return DB::transaction(function () use ($project, $attributes, $creator): Task {
            $title = trim((string) ($attributes['title'] ?? ''));

            if ($title === '' || mb_strlen($title) > 255) {
                throw new InvalidArgumentException('A task needs a title of at most 255 characters.');
            }

            $cycle = $this->guard->resolveCycle($project, $attributes['monthly_cycle_id'] ?? null);
            $this->guard->ensureCycleNotLocked($cycle, 'create tasks in it');
            $this->guard->ensureAssignable($project, $attributes['assigned_user_id'] ?? null);

            $priority = $attributes['priority'] ?? TaskPriority::Normal;

            $task = new Task([
                'title' => $title,
                'description' => $attributes['description'] ?? null,
                'category' => $attributes['category'] ?? null,
                'priority' => $priority instanceof TaskPriority ? $priority : TaskPriority::from($priority),
                'due_date' => $attributes['due_date'] ?? null,
            ]);

            $task->project_id = $project->getKey();
            $task->monthly_cycle_id = $cycle?->getKey();
            $task->assigned_user_id = filled($attributes['assigned_user_id'] ?? null) ? (int) $attributes['assigned_user_id'] : null;
            $task->created_by = $creator->getKey();

            SetTaskStatusAction::applyStatus($task, $attributes['status'] ?? TaskStatus::Pending);

            $task->save();

            return $task;
        });
    }
}
