<?php

namespace App\Actions\Tasks;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Services\Tasks\TaskIntegrityGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateTaskAction
{
    public function __construct(
        protected TaskIntegrityGuard $guard,
    ) {}

    /**
     * Update an existing task. Only keys present in $attributes change.
     * The project and the source template item are never changed here.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Task $task, array $attributes): Task
    {
        return DB::transaction(function () use ($task, $attributes): Task {
            $project = $task->project;

            // Source and (possible) destination cycles are row-locked together,
            // in deterministic order, and re-checked fresh.
            $moving = array_key_exists('monthly_cycle_id', $attributes);
            $destination = $moving ? $this->guard->resolveCycle($project, $attributes['monthly_cycle_id']) : null;

            $this->guard->lockCyclesForMove(
                $task->monthly_cycle_id,
                $moving ? $destination?->getKey() : $task->monthly_cycle_id,
                'edit its tasks',
                'move tasks into it',
            );

            if ($moving) {
                $task->monthly_cycle_id = $destination?->getKey();
            }

            if (array_key_exists('assigned_user_id', $attributes)) {
                $this->guard->ensureAssignable($project, $attributes['assigned_user_id']);
                $task->assigned_user_id = filled($attributes['assigned_user_id']) ? (int) $attributes['assigned_user_id'] : null;
            }

            if (array_key_exists('title', $attributes)) {
                $title = trim((string) $attributes['title']);

                if ($title === '' || mb_strlen($title) > 255) {
                    throw new InvalidArgumentException('A task needs a title of at most 255 characters.');
                }

                $task->title = $title;
            }

            foreach (['description', 'category', 'due_date'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $task->{$field} = $attributes[$field];
                }
            }

            if (array_key_exists('priority', $attributes)) {
                $priority = $attributes['priority'];
                $task->priority = $priority instanceof TaskPriority ? $priority : TaskPriority::from($priority);
            }

            if (array_key_exists('status', $attributes)) {
                SetTaskStatusAction::applyStatus($task, $attributes['status']);
            }

            $task->save();

            return $task;
        });
    }
}
