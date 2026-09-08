<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Actions\Reports\SyncReportSectionStatusesAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Services\Reports\ReportReadinessService;
use App\Support\Reports\ReportReadiness;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Project → Reports (minimal): one row per reporting month with the
 * report's status and live readiness, plus "Ensure draft report" and a
 * minimal executive-summary editor. The full editor, review workflow,
 * preview and PDF arrive in Milestone 13.
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403), rows are the project's own cycles, and every write is authorized
 * by MonthlyCyclePolicy / MonthlyReportPolicy. Not a global sidebar module.
 */
class ProjectReports extends ResourcePage implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-reports';

    protected static ?string $title = 'Reports';

    /**
     * @var array<int, ReportReadiness>
     */
    protected array $readinessCache = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
        abort_unless(Gate::allows('viewReports', $this->getRecord()), 403);
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
     * Live readiness for a cycle's report (null when no report exists yet).
     */
    public function readinessFor(MonthlyCycle $cycle): ?ReportReadiness
    {
        $report = $cycle->monthlyReport;

        if ($report === null) {
            return null;
        }

        return $this->readinessCache[$cycle->getKey()] ??= app(ReportReadinessService::class)->evaluate($report);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => MonthlyCycle::query()
                ->where('project_id', $this->getProject()->getKey())
                ->with('monthlyReport')
                ->latestPeriodFirst())
            ->paginated(false)
            ->columns([
                TextColumn::make('period')
                    ->label('Period')
                    ->state(fn (MonthlyCycle $record): string => $record->periodLabel())
                    ->description(fn (MonthlyCycle $record): ?string => $record->isLocked() ? 'Locked' : null),
                TextColumn::make('report_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (MonthlyCycle $record): string => $record->monthlyReport?->status->getLabel() ?? 'Not started')
                    ->color(fn (MonthlyCycle $record): string => $record->monthlyReport?->status->getColor() ?? 'gray'),
                TextColumn::make('readiness')
                    ->label('Readiness')
                    ->state(fn (MonthlyCycle $record): string => ($r = $this->readinessFor($record)) ? $r->percentage().'%' : '—')
                    ->badge()
                    ->color(fn (MonthlyCycle $record): string => match (true) {
                        ($r = $this->readinessFor($record)) === null => 'gray',
                        $r->isReady() => 'success',
                        default => 'warning',
                    }),
                TextColumn::make('required_sections')
                    ->label('Required sections')
                    ->state(fn (MonthlyCycle $record): string => ($r = $this->readinessFor($record))
                        ? $r->completedRequiredCount().' / '.$r->requiredCount().' complete'
                        : '—')
                    ->description(fn (MonthlyCycle $record): ?string => ($r = $this->readinessFor($record)) && ! $r->isReady()
                        ? 'Missing: '.$r->missing()->map(fn ($s) => $s->title)->implode(', ')
                        : null),
            ])
            ->recordActions([
                Action::make('ensureReport')
                    ->label('Create draft report')
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->visible(fn (MonthlyCycle $record): bool => $record->monthlyReport === null)
                    ->authorize(fn (MonthlyCycle $record): bool => Gate::allows('ensureReport', $record))
                    ->action(function (MonthlyCycle $record, Action $action): void {
                        Gate::authorize('ensureReport', $record);

                        $this->runDomain($action, fn () => app(EnsureMonthlyReportAction::class)->handle($record), 'Draft report ready');
                    }),
                Action::make('editSummary')
                    ->label('Executive summary')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(fn (MonthlyCycle $record): string => 'Executive summary — '.$record->periodLabel())
                    ->modalWidth('2xl')
                    ->schema([
                        Textarea::make('executive_summary')
                            ->label('Executive summary')
                            ->rows(8)
                            ->maxLength(UpdateMonthlyReportDraftAction::SUMMARY_MAX)
                            ->nullable()
                            ->helperText('A non-empty summary completes the Executive Summary section.'),
                    ])
                    ->fillForm(fn (MonthlyCycle $record): array => ['executive_summary' => $record->monthlyReport?->executive_summary])
                    ->visible(fn (MonthlyCycle $record): bool => $record->monthlyReport !== null)
                    ->authorize(fn (MonthlyCycle $record): bool => $record->monthlyReport !== null && Gate::allows('prepare', $record->monthlyReport))
                    ->action(function (MonthlyCycle $record, array $data, Action $action): void {
                        $report = $record->monthlyReport()->firstOrFail();

                        Gate::authorize('prepare', $report);

                        $this->runDomain($action, function () use ($report, $data): void {
                            app(UpdateMonthlyReportDraftAction::class)->handle($report, $data);
                            app(SyncReportSectionStatusesAction::class)->handle($report);
                        }, 'Executive summary saved');
                    }),
                Action::make('refreshReadiness')
                    ->label('Check readiness')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (MonthlyCycle $record): bool => $record->monthlyReport !== null)
                    ->authorize(fn (MonthlyCycle $record): bool => Gate::allows('viewReports', $this->getProject()))
                    ->action(function (MonthlyCycle $record): void {
                        $readiness = app(SyncReportSectionStatusesAction::class)->handle($record->monthlyReport()->firstOrFail());

                        unset($this->readinessCache[$record->getKey()]);

                        Notification::make()
                            ->title($readiness->isReady() ? 'Report is ready for review' : 'Report is not ready: '.$readiness->label())
                            ->body($readiness->isReady() ? null : $readiness->missing()->map(fn ($s) => $s->title.' — '.$s->reason)->implode("\n"))
                            ->color($readiness->isReady() ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No monthly cycles yet')
            ->emptyStateDescription('Reports are prepared per reporting month once the project has monthly cycles.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProject')
                ->label('Back to project')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('view', ['record' => $this->getRecord()])),
            Action::make('reportSections')
                ->label('Report sections')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('manageReportSections', $this->getProject()))
                ->url(fn (): string => ProjectResource::getUrl('report-sections', ['record' => $this->getRecord()])),
        ];
    }

    protected function runDomain(Action $action, callable $call, string $successTitle): void
    {
        try {
            $call();
        } catch (InvalidArgumentException|LockedMonthlyCycleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();
        }

        $this->readinessCache = [];

        Notification::make()->title($successTitle)->success()->send();
    }
}
