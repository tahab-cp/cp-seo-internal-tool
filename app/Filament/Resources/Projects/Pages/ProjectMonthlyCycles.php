<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\MonthlyCycles\EnsureMonthlyCycleAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Minimal monthly-cycle view for a project: available months, the selected
 * cycle's status and its *stored* target snapshot. Progress, tasks and
 * reporting arrive in later milestones.
 *
 * The record is resolved through ProjectResource::getEloquentQuery(), so an
 * unrelated project does not exist for an SEO Executive (404), and the
 * project policy's `view` ability is checked on mount (403).
 */
class ProjectMonthlyCycles extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-monthly-cycles';

    protected static ?string $title = 'Monthly cycles';

    public ?int $selectedCycleId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->selectedCycleId = $this->defaultCycle()?->getKey();
    }

    public function getSubheading(): ?string
    {
        return $this->getProject()->name;
    }

    public function getProject(): Project
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return $project;
    }

    /**
     * @return Collection<int, MonthlyCycle>
     */
    public function getCycles(): Collection
    {
        return $this->getProject()
            ->monthlyCycles()
            ->with('targets')
            ->latestPeriodFirst()
            ->get();
    }

    public function getSelectedCycle(): ?MonthlyCycle
    {
        return $this->getCycles()->firstWhere('id', $this->selectedCycleId);
    }

    public function getCurrentPeriod(): CyclePeriod
    {
        return CyclePeriod::current();
    }

    public function currentCycleIsMissing(): bool
    {
        return ! $this->getProject()
            ->monthlyCycles()
            ->forPeriod($this->getCurrentPeriod())
            ->exists();
    }

    protected function defaultCycle(): ?MonthlyCycle
    {
        $cycles = $this->getCycles();
        $current = $this->getCurrentPeriod();

        return $cycles->first(fn (MonthlyCycle $cycle): bool => $cycle->period()->equals($current))
            ?? $cycles->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProject')
                ->label('Back to project')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('view', ['record' => $this->getRecord()])),
            Action::make('ensureCurrentMonth')
                ->label(fn (): string => 'Ensure '.$this->getCurrentPeriod()->label())
                ->icon(Heroicon::OutlinedCalendarDays)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => 'Create the '.$this->getCurrentPeriod()->label().' cycle')
                ->modalDescription('The project\'s current package targets and overrides will be snapshotted for this month.')
                ->authorize(fn (): bool => Gate::allows('ensureMonthlyCycle', $this->getProject()))
                ->visible(fn (): bool => $this->currentCycleIsMissing())
                ->action(function (): void {
                    // Re-check server-side so a crafted request cannot bypass the policy.
                    Gate::authorize('ensureMonthlyCycle', $this->getProject());

                    $cycle = app(EnsureMonthlyCycleAction::class)->handle($this->getProject());

                    $this->selectedCycleId = $cycle->getKey();

                    Notification::make()
                        ->title($cycle->periodLabel().' cycle ready')
                        ->success()
                        ->send();
                }),
        ];
    }
}
