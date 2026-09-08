<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\GenerateOnboardingTasksAction;
use App\Actions\Tasks\SetTaskStatusAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\TaskForm;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Project → Tasks: a fast table of the project's tasks with quick views.
 *
 * The record is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403) and the table query runs through Task::scopeAccessibleBy(). Every
 * action is authorized by TaskPolicy / ProjectPolicy and re-checked in the
 * action closures, and all writes go through the task actions.
 */
class ProjectTasks extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-tasks';

    protected static ?string $title = 'Tasks';

    /**
     * Monthly context: /admin/projects/{record}/tasks?cycle=ID
     */
    public ?int $contextCycleId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $cycleId = request()->integer('cycle');

        if ($cycleId > 0 && $this->getProject()->monthlyCycles()->whereKey($cycleId)->exists()) {
            $this->contextCycleId = $cycleId;
        }
    }

    public function getSubheading(): ?string
    {
        $cycle = $this->getContextCycle();

        return $this->getProject()->name.($cycle ? ' — '.$cycle->periodLabel() : '');
    }

    public function getProject(): Project
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return $project;
    }

    public function getContextCycle(): ?MonthlyCycle
    {
        return $this->contextCycleId
            ? $this->getProject()->monthlyCycles()->find($this->contextCycleId)
            : null;
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Task::query()
                ->where('project_id', $this->getProject()->getKey())
                ->accessibleBy($this->currentUser())
                ->with(['assignee', 'monthlyCycle']))
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('title')
                    ->label('Task')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Task $record): string => $record->monthlyCycle?->periodLabel() ?? 'Project-level'),
                TextColumn::make('assignee.name')
                    ->label('Assignee')
                    ->placeholder('Unassigned'),
                TextColumn::make('category')
                    ->placeholder('—'),
                TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn (Task $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('priority')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('view')
                    ->label('View')
                    ->options([
                        'all' => 'All',
                        'current_month' => 'Current month',
                        'mine' => 'Mine',
                        'overdue' => 'Overdue',
                        'completed' => 'Completed',
                    ])
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'all') {
                        'current_month' => $query->forPeriod(CyclePeriod::current()),
                        'mine' => $query->assignedTo($this->currentUser()),
                        'overdue' => $query->overdue(),
                        'completed' => $query->completed(),
                        default => $query,
                    }),
                SelectFilter::make('priority')
                    ->options(TaskPriority::class),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit task')
                    ->schema(fn (): array => TaskForm::components($this->getProject()))
                    ->fillForm(fn (Task $record): array => TaskForm::fillFromTask($record))
                    ->authorize(fn (Task $record): bool => Gate::allows('update', $record))
                    ->action(function (Task $record, array $data): void {
                        Gate::authorize('update', $record);

                        app(UpdateTaskAction::class)->handle($record, TaskForm::attributesFromData($data));

                        Notification::make()->title('Task updated')->success()->send();
                    }),
                Action::make('start')
                    ->label('Start')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->color('info')
                    ->visible(fn (Task $record): bool => in_array($record->status, [TaskStatus::Pending, TaskStatus::Blocked], true))
                    ->authorize(fn (Task $record): bool => Gate::allows('setStatus', $record))
                    ->action(function (Task $record): void {
                        Gate::authorize('setStatus', $record);

                        app(SetTaskStatusAction::class)->handle($record, TaskStatus::InProgress);
                    }),
                Action::make('complete')
                    ->label('Complete')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Task $record): bool => $record->status->isOpen())
                    ->authorize(fn (Task $record): bool => Gate::allows('setStatus', $record))
                    ->action(function (Task $record): void {
                        Gate::authorize('setStatus', $record);

                        app(SetTaskStatusAction::class)->handle($record, TaskStatus::Completed);
                    }),
                Action::make('setStatus')
                    ->label('Status')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->modalHeading('Change status')
                    ->modalWidth('sm')
                    ->schema([
                        Select::make('status')
                            ->options(TaskStatus::class)
                            ->required()
                            ->native(false),
                    ])
                    ->fillForm(fn (Task $record): array => ['status' => $record->status->value])
                    ->authorize(fn (Task $record): bool => Gate::allows('setStatus', $record))
                    ->action(function (Task $record, array $data): void {
                        Gate::authorize('setStatus', $record);

                        app(SetTaskStatusAction::class)->handle($record, $data['status']);
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading('No tasks yet')
            ->emptyStateDescription('Add tasks for this project, or generate the onboarding checklist from a task template.');
    }

    /**
     * Page-level actions: Add task (anyone who can manage the project's
     * tasks) and Generate onboarding tasks (Admin / Manager only).
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProject')
                ->label('Back to project')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('view', ['record' => $this->getRecord()])),
            Action::make('createTask')
                ->label('Add task')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Add task')
                ->schema(fn (): array => TaskForm::components($this->getProject(), $this->contextCycleId))
                ->authorize(fn (): bool => Gate::allows('manageTasks', $this->getProject()))
                ->action(function (array $data): void {
                    Gate::authorize('manageTasks', $this->getProject());

                    app(CreateTaskAction::class)->handle(
                        $this->getProject(),
                        TaskForm::attributesFromData($data),
                        $this->currentUser(),
                    );

                    Notification::make()->title('Task added')->success()->send();
                }),
            Action::make('generateOnboarding')
                ->label('Generate onboarding tasks')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->modalHeading('Generate onboarding tasks')
                ->modalDescription('Copies the template into project-level tasks. Items already generated for this project are skipped, so this is safe to repeat.')
                ->schema([
                    Select::make('task_template_id')
                        ->label('Onboarding template')
                        ->options(fn (): array => TaskTemplate::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->rule(Rule::exists('task_templates', 'id')->where('is_active', true)),
                ])
                ->authorize(fn (): bool => Gate::allows('generateOnboarding', $this->getProject()))
                ->action(function (array $data): void {
                    Gate::authorize('generateOnboarding', $this->getProject());

                    $template = TaskTemplate::query()->active()->findOrFail($data['task_template_id']);

                    $created = app(GenerateOnboardingTasksAction::class)->handle(
                        $this->getProject(),
                        $template,
                        $this->currentUser(),
                    );

                    Notification::make()
                        ->title($created->count().' onboarding task(s) generated')
                        ->success()
                        ->send();
                }),
        ];
    }
}
