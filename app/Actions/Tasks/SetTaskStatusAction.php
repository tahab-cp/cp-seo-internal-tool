<?php

namespace App\Actions\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\Tasks\TaskIntegrityGuard;
use Illuminate\Support\Facades\DB;

class SetTaskStatusAction
{
    public function __construct(
        protected TaskIntegrityGuard $guard,
    ) {}

    /**
     * Change a task's status, keeping completed_at consistent. Refused for
     * tasks inside a locked monthly cycle.
     */
    public function handle(Task $task, TaskStatus|string $status): Task
    {
        return DB::transaction(function () use ($task, $status): Task {
            $this->guard->ensureTaskNotLocked($task, 'change the status of its tasks');

            static::applyStatus($task, $status);

            $task->save();

            return $task;
        });
    }

    /**
     * The one place completion timestamps are decided:
     * - becoming completed stamps completed_at (kept if already completed);
     * - any other status, including cancelled, clears it.
     */
    public static function applyStatus(Task $task, TaskStatus|string $status): void
    {
        $status = $status instanceof TaskStatus ? $status : TaskStatus::from($status);

        $task->status = $status;

        $task->completed_at = $status->isCompleted()
            ? ($task->completed_at ?? now())
            : null;
    }
}
