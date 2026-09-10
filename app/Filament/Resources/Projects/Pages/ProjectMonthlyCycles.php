<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\MonthlyCycles\EnsureMonthlyCycleAction;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
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
 * Project → Monthly cycles: the project's reporting months, the selected
 * month's status and the targets stored for it, with links into that
 * month's work. Presentation reads the existing records only.
 *
 * The record is resolved through ProjectResource::getEloquentQuery(), so an
 * unrelated project does not exist for an SEO Executive (404), and the
 * project policy's `view` ability is checked on mount (403).
 */
class ProjectMonthlyCycles extends Page
{
    use HasProjectWorkspace;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-monthly-cycles';

    public ?int $selectedCycleId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->selectedCycleId = $this->defaultCycle()?->getKey();
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

    /**
     * Every cycle with its stored targets, the user who locked it and its
     * report, newest first (one query set for the whole screen).
     *
     * @return Collection<int, MonthlyCycle>
     */
    public function getCycles(): Collection
    {
        return $this->getProject()
            ->monthlyCycles()
            ->with(['targets', 'lockedBy', 'monthlyReport'])
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

    /**
     * Links into the selected month's work: existing routes only.
     *
     * @return list<array{key: string, label: string, icon: string, url: string}>
     */
    public function getWorkLinks(MonthlyCycle $cycle): array
    {
        $project = $this->getRecord();
        $report = $cycle->monthlyReport;

        return [
            ['key' => 'tasks', 'label' => 'View tasks', 'icon' => 'heroicon-o-clipboard-document-list', 'url' => ProjectResource::getUrl('tasks', ['record' => $project, 'cycle' => $cycle->getKey()])],
            ['key' => 'monthly-work', 'label' => 'Monthly work', 'icon' => 'heroicon-o-light-bulb', 'url' => ProjectResource::getUrl('monthly-work', ['record' => $project, 'cycle' => $cycle->getKey()])],
            $report
                ? ['key' => 'report', 'label' => $report->isFinal() ? 'View report' : 'Open report', 'icon' => 'heroicon-o-document-chart-bar', 'url' => ProjectResource::getUrl('report', ['record' => $project, 'report' => $report])]
                : ['key' => 'reports', 'label' => 'Reports', 'icon' => 'heroicon-o-document-chart-bar', 'url' => ProjectResource::getUrl('reports', ['record' => $project])],
        ];
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
            Action::make('ensureCurrentMonth')
                ->label(fn (): string => 'Create '.$this->getCurrentPeriod()->label().' cycle')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->requiresConfirmation()
                ->modalHeading(fn (): string => 'Create the '.$this->getCurrentPeriod()->label().' cycle?')
                ->modalDescription('The project\'s current package targets and overrides will be saved as this month\'s targets.')
                ->modalSubmitActionLabel('Create cycle')
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
