<?php

namespace App\Filament\Pages;

use App\Actions\Tasks\SetTaskStatusAction;
use App\Enums\TaskStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Personal work view: tasks assigned to the current user on projects they
 * can still access. Not a team workload screen.
 */
class MyTasks extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'My Tasks';

    protected static ?string $title = 'My Tasks';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.my-tasks';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $user?->can('viewAny', Task::class) === true;
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
                ->accessibleBy($this->currentUser())
                ->assignedTo($this->currentUser())
                ->with(['project', 'monthlyCycle']))
            ->defaultSort('due_date')
            ->columns([
                TextColumn::make('project.name')
                    ->label('Project')
                    ->sortable()
                    ->url(fn (Task $record): string => ProjectResource::getUrl('tasks', ['record' => $record->project])),
                TextColumn::make('title')
                    ->label('Task')
                    ->searchable()
                    ->description(fn (Task $record): string => $record->monthlyCycle?->periodLabel() ?? 'Project-level'),
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
                        'open' => 'All open',
                        'due_today' => 'Due today',
                        'this_week' => 'This week',
                        'overdue' => 'Overdue',
                        'completed' => 'Completed',
                    ])
                    ->default('open')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'open') {
                        'due_today' => $query->dueToday(),
                        'this_week' => $query->dueThisWeek(),
                        'overdue' => $query->overdue(),
                        'completed' => $query->completed(),
                        default => $query->open(),
                    }),
            ])
            ->recordActions([
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
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading('Nothing assigned to you')
            ->emptyStateDescription('Tasks assigned to you across your projects will appear here.');
    }
}
