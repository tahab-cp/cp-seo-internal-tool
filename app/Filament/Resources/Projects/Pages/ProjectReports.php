<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Reports\EnsureMonthlyReportAction;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportRevision;
use App\Models\Project;
use App\Services\Reports\ReportReadinessService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Reports\ReportReadiness;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Project → Reports: one row per reporting month with the report's status,
 * live readiness and finalization details. Starting a draft snapshots the
 * project's report sections; everything else happens in the report editor.
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403), rows are the project's own cycles, and every write is authorized
 * by MonthlyCyclePolicy / MonthlyReportPolicy. Not a global sidebar module.
 * Presentation reads the existing records and services only.
 */
class ProjectReports extends ResourcePage implements HasTable
{
    use HasProjectWorkspace;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-reports';

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
     * The cycle whose report is summarised at the top: the current period
     * when it exists, otherwise the latest cycle.
     */
    public function getCurrentCycle(): ?MonthlyCycle
    {
        $cycles = $this->getProject()->monthlyCycles()->with('monthlyReport.finalizedBy')->latestPeriodFirst()->get();
        $current = CyclePeriod::current();

        return $cycles->first(fn (MonthlyCycle $cycle): bool => $cycle->period()->equals($current))
            ?? $cycles->first();
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

    /**
     * Everything one history card shows for a cycle, from the loaded
     * relationships and the cached readiness. No new queries per card.
     *
     * @return array{cycle: MonthlyCycle, report: ?MonthlyReport, readiness: ?ReportReadiness, revisions: Collection<int, MonthlyReportRevision>, current: bool, revision_urls: array<int, array{view: string, pdf: ?string}>}
     */
    public function historyState(MonthlyCycle $cycle): array
    {
        $report = $cycle->monthlyReport;
        $revisions = $report?->revisions ?? new Collection;

        return [
            'cycle' => $cycle,
            'report' => $report,
            'readiness' => $this->readinessFor($cycle),
            'revisions' => $revisions,
            'current' => $cycle->period()->equals(CyclePeriod::current()),
            'revision_urls' => $revisions->mapWithKeys(fn (MonthlyReportRevision $revision): array => [$revision->getKey() => [
                'view' => route('filament.admin.reports.revisions.preview', ['project' => $this->getProject()->getKey(), 'report' => $report, 'revision' => $revision]),
                'pdf' => $revision->hasPdf() && Gate::allows('downloadPdf', $revision)
                    ? route('filament.admin.reports.revisions.pdf', ['project' => $this->getProject()->getKey(), 'report' => $report, 'revision' => $revision])
                    : null,
            ]])->all(),
        ];
    }

    /**
     * The main history action per state: Open / Review / Continue correction / View.
     */
    protected function openLabel(MonthlyCycle $cycle): string
    {
        $report = $cycle->monthlyReport;

        return match (true) {
            $report === null => 'Open report',
            $report->isFinal() => 'View report',
            $report->isCorrection() => 'Continue correction',
            $report->isReadyForReview() => 'Review report',
            default => 'Open report',
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => MonthlyCycle::query()
                ->where('project_id', $this->getProject()->getKey())
                ->with(['monthlyReport.finalizedBy', 'monthlyReport.revisions.finalizedBy'])
                ->latestPeriodFirst())
            ->paginated(false)
            ->contentGrid(['default' => 1])
            ->recordActionsAlignment('end')
            ->columns([
                Stack::make([
                    ViewColumn::make('history')
                        ->label('Report history')
                        ->view('filament.resources.projects.partials.report-history-card')
                        ->state(fn (MonthlyCycle $record): array => $this->historyState($record)),
                ]),
            ])
            ->recordActions([
                $this->createDraftAction(Action::make('ensureReport')),
                Action::make('open')
                    ->label(fn (MonthlyCycle $record): string => $this->openLabel($record))
                    ->icon(fn (MonthlyCycle $record): Heroicon => $record->monthlyReport?->isFinal() ? Heroicon::OutlinedEye : Heroicon::OutlinedArrowRight)
                    ->iconPosition(IconPosition::After)
                    ->color(fn (MonthlyCycle $record): string => $record->monthlyReport?->isFinal() ? 'gray' : 'primary')
                    ->size('sm')
                    ->visible(fn (MonthlyCycle $record): bool => $record->monthlyReport !== null)
                    ->url(fn (MonthlyCycle $record): string => ProjectResource::getUrl('report', ['record' => $this->getRecord(), 'report' => $record->monthlyReport])),
                Action::make('downloadPdf')
                    ->label('Download PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('success')
                    ->outlined()
                    ->size('sm')
                    ->visible(fn (MonthlyCycle $record): bool => $record->monthlyReport !== null && Gate::allows('downloadPdf', $record->monthlyReport))
                    ->url(fn (MonthlyCycle $record): string => route('filament.admin.reports.pdf', ['project' => $this->getProject()->getKey(), 'report' => $record->monthlyReport]), shouldOpenInNewTab: true),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedDocumentChartBar)
            ->emptyStateHeading('No monthly cycles yet')
            ->emptyStateDescription('Reports are prepared per reporting month once the project has monthly cycles.');
    }

    /**
     * The single "Create draft report" workflow (EnsureMonthlyReportAction),
     * used as the row action and from the current-period summary.
     */
    protected function createDraftAction(Action $action): Action
    {
        return $action
            ->label('Create draft report')
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->size('sm')
            ->visible(fn (MonthlyCycle $record): bool => $record->monthlyReport === null)
            ->authorize(fn (MonthlyCycle $record): bool => Gate::allows('ensureReport', $record))
            ->action(function (MonthlyCycle $record, Action $action): void {
                Gate::authorize('ensureReport', $record);

                try {
                    app(EnsureMonthlyReportAction::class)->handle($record);
                } catch (InvalidArgumentException|LockedMonthlyCycleException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    $action->halt();
                }

                $this->readinessCache = [];

                Notification::make()->title('Draft report ready')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reportSections')
                ->label('Report sections')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('manageReportSections', $this->getProject()))
                ->url(fn (): string => ProjectResource::getUrl('report-sections', ['record' => $this->getRecord()])),
        ];
    }
}
