<?php

namespace App\Actions\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GenerateOnboardingTasksAction
{
    /**
     * Copy an active task template into project-level tasks.
     *
     * Idempotent per template item: an item that already produced a task for
     * this project (even a soft-deleted one) is skipped, so re-running only
     * creates tasks for items added to the template since. Existing tasks
     * are operational snapshots and are never rewritten.
     *
     * Assignment: the project's primary SEO owner when active, else unassigned.
     * Due dates: default_due_days added to the project start date, or to the
     * generation date when the project has no start date; null stays null.
     *
     * @return Collection<int, Task> the tasks created by this run
     */
    public function handle(Project $project, TaskTemplate $template, User $creator): Collection
    {
        if (! $template->is_active) {
            throw new InvalidArgumentException("Task template \"{$template->name}\" is inactive and cannot be used for onboarding.");
        }

        if (! $project->exists || $project->trashed()) {
            throw new InvalidArgumentException('Onboarding tasks can only be generated for existing, non-archived projects.');
        }

        return DB::transaction(function () use ($project, $template, $creator): Collection {
            $alreadyGenerated = $project->tasks()
                ->withTrashed()
                ->whereNotNull('task_template_item_id')
                ->pluck('task_template_item_id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();

            $owner = $project->primarySeoUser;
            $assigneeId = $owner?->is_active ? $owner->getKey() : null;

            $baseDate = $project->start_date !== null
                ? CarbonImmutable::parse($project->start_date)
                : CarbonImmutable::today();

            $created = collect();

            foreach ($template->items()->get() as $item) {
                /** @var TaskTemplateItem $item */
                if (in_array($item->getKey(), $alreadyGenerated, true)) {
                    continue;
                }

                $task = new Task([
                    'title' => $item->title,
                    'description' => $item->description,
                    'category' => $item->category,
                    'priority' => TaskPriority::Normal,
                    'due_date' => $item->default_due_days === null
                        ? null
                        : $baseDate->addDays($item->default_due_days)->toDateString(),
                ]);

                $task->project_id = $project->getKey();
                $task->monthly_cycle_id = null;
                $task->task_template_item_id = $item->getKey();
                $task->assigned_user_id = $assigneeId;
                $task->created_by = $creator->getKey();
                $task->status = TaskStatus::Pending;
                $task->completed_at = null;

                $task->save();

                $created->push($task);
            }

            return $created;
        });
    }
}
