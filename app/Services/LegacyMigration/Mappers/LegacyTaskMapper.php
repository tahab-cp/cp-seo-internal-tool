<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Tasks\CreateTaskAction;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * "Tasks" sheet: legacy checklist rows. Status resolves only through the
 * enum or the explicit "task_status" mapping: an ambiguous checkbox word
 * is an issue, never silently "completed". A blank status means pending.
 * Task completion never touches SEO deliverable targets.
 */
class LegacyTaskMapper extends LegacySheetMapper
{
    public function __construct(
        protected CreateTaskAction $createTask,
    ) {}

    public function sheet(): string
    {
        return 'Tasks';
    }

    public function fields(): array
    {
        return [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'title' => ['aliases' => ['task', 'task title', 'item', 'checklist item'], 'required' => true],
            'status' => ['aliases' => ['task status', 'done', 'state']],
            'due_date' => ['aliases' => ['due', 'deadline']],
            'assignee' => ['aliases' => ['assigned to', 'owner', 'assigned']],
            'period' => ['aliases' => ['month', 'reporting month']],
            'category' => ['aliases' => ['area', 'group']],
            'priority' => [],
            'description' => ['aliases' => ['details', 'notes']],
        ];
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'project', 'title');

                $project = $this->project($context, $row);
                $status = $this->blank($row, 'status') ? TaskStatus::Pending : $context->values->enum('task_status', $this->value($row, 'status'), TaskStatus::class);
                $priority = $this->blank($row, 'priority') ? TaskPriority::Normal : $context->values->enum('task_priority', $this->value($row, 'priority'), TaskPriority::class);
                $dueDate = $this->blank($row, 'due_date') ? null : $context->values->date($this->value($row, 'due_date'));

                $fingerprint = $context->ledger::fingerprint(['task', $project->getKey(), $this->value($row, 'title'), $dueDate, $this->value($row, 'period')]);

                if (! $this->shouldCreate($context, $sheet, $rowNumber, 'tasks', $fingerprint)) {
                    continue;
                }

                $cycle = null;

                if ($this->has('period') && ! $this->blank($row, 'period')) {
                    $cycle = $this->cycle($context, $project, $context->values->period($this->value($row, 'period')), $sheet, $rowNumber);

                    if ($cycle === null) {
                        $context->tally('tasks', MigrationOutcome::CONFLICT);

                        continue;
                    }
                }

                $assignee = null;

                if (! $this->blank($row, 'assignee')) {
                    $assignee = $context->values->user($this->value($row, 'assignee'));

                    if ($assignee === null || ! $assignee->is_active || ! Gate::forUser($assignee)->allows('view', $project)) {
                        $context->warning($sheet->name, $rowNumber, 'task', sprintf('Assignee "%s" does not resolve to an active user with access to "%s"; task left unassigned (users are never created by migration).', $this->value($row, 'assignee'), $project->name));
                        $assignee = null;
                    }
                }

                $task = $this->createTask->handle($project, [
                    'title' => $this->value($row, 'title'),
                    'description' => $this->optional($row, 'description'),
                    'category' => $this->optional($row, 'category'),
                    'status' => $status,
                    'priority' => $priority,
                    'due_date' => $dueDate,
                    'monthly_cycle_id' => $cycle?->getKey(),
                    'assigned_user_id' => $assignee?->getKey(),
                ], $context->actor);

                $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'task', (int) $task->getKey());
                $context->tally('tasks', MigrationOutcome::CREATE);
            } catch (InvalidArgumentException $exception) {
                $context->tally('tasks', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'task', $exception->getMessage(), $row);
            }
        }
    }
}
