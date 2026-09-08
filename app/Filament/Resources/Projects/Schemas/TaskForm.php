<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\MonthlyCycleStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskIntegrityGuard;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Task create/edit fields for one project. The selects are pre-filtered
 * for convenience; the task actions re-validate every rule server-side.
 */
class TaskForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(Project $project, ?int $defaultCycleId = null): array
    {
        $assignable = app(TaskIntegrityGuard::class)->assignableUsers($project);

        $cycles = $project->monthlyCycles()
            ->where('status', '!=', MonthlyCycleStatus::Locked->value)
            ->latestPeriodFirst()
            ->get();

        return [
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Select::make('status')
                ->options(TaskStatus::class)
                ->required()
                ->default(TaskStatus::Pending->value)
                ->native(false),
            Select::make('priority')
                ->options(TaskPriority::class)
                ->required()
                ->default(TaskPriority::Normal->value)
                ->native(false),
            Select::make('assigned_user_id')
                ->label('Assignee')
                ->options($assignable->pluck('name', 'id')->all())
                ->searchable()
                ->nullable()
                ->in($assignable->pluck('id')->all())
                ->helperText('Only active users who can access this project.'),
            Select::make('monthly_cycle_id')
                ->label('Monthly cycle')
                ->options($cycles->mapWithKeys(fn (MonthlyCycle $cycle): array => [$cycle->id => $cycle->periodLabel()])->all())
                ->placeholder('Project-level (no month)')
                ->default($defaultCycleId)
                ->nullable()
                ->native(false)
                ->rule(Rule::exists('monthly_cycles', 'id')
                    ->where('project_id', $project->getKey())
                    ->where('status', '!=', MonthlyCycleStatus::Locked->value))
                ->helperText('Leave empty for a project-level task. Locked months cannot be selected.'),
            TextInput::make('category')
                ->maxLength(100),
            DatePicker::make('due_date')
                ->label('Due date')
                ->native(false),
            Textarea::make('description')
                ->rows(4)
                ->maxLength(5000)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromTask(Task $task): array
    {
        return [
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'assigned_user_id' => $task->assigned_user_id,
            'monthly_cycle_id' => $task->monthly_cycle_id,
            'category' => $task->category,
            'due_date' => $task->due_date?->toDateString(),
            'description' => $task->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributesFromData(array $data): array
    {
        return [
            'title' => $data['title'] ?? null,
            'status' => $data['status'] ?? TaskStatus::Pending->value,
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'monthly_cycle_id' => $data['monthly_cycle_id'] ?? null,
            'category' => $data['category'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * Keeps the guard's filter reusable for tests and other callers.
     *
     * @return Collection<int, User>
     */
    public static function assignableUsers(Project $project): Collection
    {
        return app(TaskIntegrityGuard::class)->assignableUsers($project);
    }
}
