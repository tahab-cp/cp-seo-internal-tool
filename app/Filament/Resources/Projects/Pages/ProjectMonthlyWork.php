<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Actions\Notes\DeleteMonthlyNoteAction;
use App\Actions\Notes\UpdateMonthlyNoteAction;
use App\Enums\MonthlyNoteType;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\MonthlyNoteForm;
use App\Models\MonthlyCycle;
use App\Models\MonthlyNote;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Project → Monthly Work: the selected month's notes grouped into Wins,
 * Challenges & Observations, Recommendations and Next month focus.
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403), notes are always resolved through the project's own cycles, and
 * every write is authorized by MonthlyCyclePolicy / MonthlyNotePolicy and
 * re-checked in the note actions. Not a global sidebar module.
 * Presentation reads the existing records only.
 */
class ProjectMonthlyWork extends ResourcePage
{
    use HasProjectWorkspace;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-monthly-work';

    public string $selectedCycle = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $requested = (int) request()->query('cycle');

        if ($requested > 0 && $this->getProject()->monthlyCycles()->whereKey($requested)->exists()) {
            $this->selectedCycle = (string) $requested;
        } else {
            $this->selectedCycle = (string) ($this->defaultCycle()?->getKey() ?? '');
        }
    }

    public function getTitle(): string
    {
        return $this->getProject()->name;
    }

    public function getSubheading(): ?string
    {
        return $this->getWorkspaceSubheading();
    }

    public function getProject(): Project
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return $project;
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /**
     * @return Collection<int, MonthlyCycle>
     */
    public function getCycles(): Collection
    {
        return $this->getProject()->monthlyCycles()->latestPeriodFirst()->get();
    }

    public function getSelectedCycle(): ?MonthlyCycle
    {
        if ((int) $this->selectedCycle <= 0) {
            return null;
        }

        return $this->getProject()->monthlyCycles()->find((int) $this->selectedCycle);
    }

    protected function requireSelectedCycle(): MonthlyCycle
    {
        return $this->getSelectedCycle()
            ?? throw new InvalidArgumentException('Select a reporting month first.');
    }

    protected function defaultCycle(): ?MonthlyCycle
    {
        $cycles = $this->getCycles();
        $current = CyclePeriod::current();

        return $cycles->first(fn (MonthlyCycle $cycle): bool => $cycle->period()->equals($current))
            ?? $cycles->first();
    }

    /**
     * Notes of the selected month grouped into the four lanes of the screen:
     * wins, challenges (challenges and observations), recommendations and focus
     * (next month focus). One query; the counts come from the same collection.
     *
     * @return array<string, Collection<int, MonthlyNote>>
     */
    public function getGroupedNotes(): array
    {
        $notes = $this->getSelectedCycle()?->monthlyNotes()->with('createdBy')->ordered()->get() ?? new Collection;

        $lane = fn (MonthlyNoteType ...$types): Collection => $notes
            ->filter(fn (MonthlyNote $note): bool => in_array($note->type, $types, true))
            ->values();

        return [
            'wins' => $lane(MonthlyNoteType::Win),
            'challenges' => $lane(MonthlyNoteType::Challenge, MonthlyNoteType::Observation),
            'recommendations' => $lane(MonthlyNoteType::Recommendation),
            'focus' => $lane(MonthlyNoteType::NextMonthFocus),
        ];
    }

    public function canManageSelectedCycle(): bool
    {
        $cycle = $this->getSelectedCycle();

        return $cycle !== null && Gate::allows('manageNotes', $cycle);
    }

    /**
     * A note of the selected month, resolved through the project's own cycles only.
     */
    protected function resolveNote(array $arguments): MonthlyNote
    {
        return $this->requireSelectedCycle()->monthlyNotes()->findOrFail((int) ($arguments['note'] ?? 0));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * The page-level "Add note" button (type chosen in the form).
     */
    public function addNoteAction(): Action
    {
        return $this->noteCreation(Action::make('addNote')->label('Add note'));
    }

    /**
     * The small per-lane link ("Add win", "Add recommendation", …) with that
     * lane's type preselected through the `type` argument.
     */
    public function addLaneNoteAction(): Action
    {
        return $this->noteCreation(Action::make('addLaneNote')->link()->size('sm'))
            ->label(fn (array $arguments): string => ($type = $this->laneType($arguments)) ? 'Add '.strtolower($type->getLabel()) : 'Add note');
    }

    protected function laneType(array $arguments): ?MonthlyNoteType
    {
        return MonthlyNoteType::tryFrom((string) ($arguments['type'] ?? ''));
    }

    /**
     * The single note-creation workflow (MonthlyNoteForm + CreateMonthlyNoteAction)
     * shared by the page-level button and the lane links.
     */
    protected function noteCreation(Action $action): Action
    {
        return $action
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(fn (): string => 'Add note — '.($this->getSelectedCycle()?->periodLabel() ?? ''))
            ->modalWidth('lg')
            ->schema(fn (array $arguments): array => MonthlyNoteForm::components($this->laneType($arguments)))
            ->authorize(fn (): bool => $this->canManageSelectedCycle())
            ->action(function (array $data, Action $action): void {
                $this->runDomain($action, function () use ($data): void {
                    $cycle = $this->requireSelectedCycle();

                    Gate::authorize('manageNotes', $cycle);

                    app(CreateMonthlyNoteAction::class)->handle($cycle, $data, $this->currentUser());
                }, 'Note added');
            });
    }

    public function editNoteAction(): Action
    {
        return Action::make('editNote')
            ->label('Edit')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->size('xs')
            ->color('gray')
            ->link()
            ->modalHeading('Edit note')
            ->modalWidth('lg')
            ->schema(MonthlyNoteForm::components())
            ->fillForm(fn (array $arguments): array => MonthlyNoteForm::fillFromNote($this->resolveNote($arguments)))
            ->authorize(fn (): bool => $this->canManageSelectedCycle())
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->runDomain($action, function () use ($data, $arguments): void {
                    $note = $this->resolveNote($arguments);

                    Gate::authorize('update', $note);

                    app(UpdateMonthlyNoteAction::class)->handle($note, $data);
                }, 'Note updated');
            });
    }

    public function deleteNoteAction(): Action
    {
        return Action::make('deleteNote')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->size('xs')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Delete note')
            ->modalDescription('The note is removed from this month. This cannot be undone.')
            ->authorize(fn (): bool => $this->canManageSelectedCycle())
            ->action(function (array $arguments, Action $action): void {
                $this->runDomain($action, function () use ($arguments): void {
                    $note = $this->resolveNote($arguments);

                    Gate::authorize('delete', $note);

                    app(DeleteMonthlyNoteAction::class)->handle($note);
                }, 'Note deleted');
            });
    }

    protected function runDomain(Action $action, callable $call, string $successTitle): void
    {
        try {
            $call();
        } catch (InvalidArgumentException|LockedMonthlyCycleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
