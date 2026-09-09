<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\SyncReportSectionStatusesAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Actions\Reports\UpdateMonthlyReportDraftAction;
use App\Actions\Reports\UpdateReportReviewNotesAction;
use App\Actions\Reports\UpdateReportSectionTextAction;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\PdfGenerationException;
use App\Exceptions\ReportNotReadyException;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycleAuditEvent;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportRevision;
use App\Models\MonthlyReportSection;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\ReportReadinessService;
use App\Services\Reports\ReportSnapshotBuilder;
use App\Support\Reports\ReportReadiness;
use App\Support\Reports\SectionReadiness;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Project → Reports → one report: the editor / workflow page.
 *
 * Header: project, client, period, status, live readiness. Body: the
 * report's SNAPSHOTTED sections in snapshot order, each with its live
 * Complete / Missing state, a glimpse of the source data it will render,
 * and optional commentary. Workflow actions (Preview, Mark Ready,
 * Finalize, Download PDF) delegate to the domain actions; nothing about
 * readiness, snapshots, Chromium or locking lives here.
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects); the report must belong to that project and the
 * `view` ability is checked on mount (403).
 */
class ProjectReportEditor extends ResourcePage
{
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-report-editor';

    protected static ?string $title = 'Report';

    public int $reportId;

    public function mount(int|string $record, int|string $report): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $monthlyReport = MonthlyReport::query()
            ->whereHas('monthlyCycle', fn ($query) => $query->where('project_id', $this->getProject()->getKey()))
            ->findOrFail((int) $report);

        abort_unless(Gate::allows('view', $monthlyReport), 403);

        $this->reportId = $monthlyReport->getKey();
    }

    public function getTitle(): string
    {
        $report = $this->getReport();

        return 'Report — '.$report->monthlyCycle->periodLabel().' ('.$report->versionLabel().')';
    }

    public function getSubheading(): ?string
    {
        $project = $this->getProject();

        return $project->name.($project->client ? ' · '.$project->client->name : '');
    }

    public function getProject(): Project
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return $project;
    }

    /**
     * Always re-read: readiness and status change under the user's feet.
     */
    public function getReport(): MonthlyReport
    {
        return MonthlyReport::query()
            ->with(['monthlyCycle.project.client', 'finalizedBy', 'revisions'])
            ->findOrFail($this->reportId);
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    public function getReadiness(): ReportReadiness
    {
        return app(ReportReadinessService::class)->evaluate($this->getReport());
    }

    /**
     * @return Collection<int, MonthlyReportSection>
     */
    public function getSections(): Collection
    {
        return $this->getReport()->sections()->get();
    }

    /**
     * A compact, read-only glimpse of what each section will render, from
     * the same builder the preview and PDF use.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSectionData(): array
    {
        $report = $this->getReport();
        $snapshot = $report->isFinal() && is_array($report->snapshot_json)
            ? $report->snapshot_json
            : app(ReportSnapshotBuilder::class)->build($report);

        return collect($snapshot['sections'] ?? [])->keyBy('key')->all();
    }

    public function previewUrl(): string
    {
        return route('filament.admin.reports.preview', ['project' => $this->getProject()->getKey(), 'report' => $this->reportId]);
    }

    public function pdfUrl(): string
    {
        return route('filament.admin.reports.pdf', ['project' => $this->getProject()->getKey(), 'report' => $this->reportId]);
    }

    public function canPrepare(): bool
    {
        return Gate::allows('prepare', $this->getReport());
    }

    /**
     * Archived finals, newest first.
     *
     * @return Collection<int, MonthlyReportRevision>
     */
    public function getRevisions(): Collection
    {
        return $this->getReport()->revisions()->with(['finalizedBy', 'archivedBy'])->get();
    }

    /**
     * Immutable lifecycle events for this report, oldest first.
     *
     * @return Collection<int, MonthlyCycleAuditEvent>
     */
    public function getAuditEvents(): Collection
    {
        return $this->getReport()->auditEvents()->with('user')->get();
    }

    public function revisionPreviewUrl(MonthlyReportRevision $revision): string
    {
        return route('filament.admin.reports.revisions.preview', ['project' => $this->getProject()->getKey(), 'report' => $this->reportId, 'revision' => $revision->getKey()]);
    }

    public function revisionPdfUrl(MonthlyReportRevision $revision): string
    {
        return route('filament.admin.reports.revisions.pdf', ['project' => $this->getProject()->getKey(), 'report' => $this->reportId, 'revision' => $revision->getKey()]);
    }

    public function unlockAction(): Action
    {
        return Action::make('unlock')
            ->label('Unlock for Correction')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('danger')
            ->modalHeading('Unlock this reporting month for correction')
            ->modalDescription('Unlocking allows this reporting month to be corrected. The current final report will be permanently preserved as a historical version. The corrected report must be reviewed and finalized again.')
            ->modalSubmitActionLabel('Unlock for Correction')
            ->modalWidth('2xl')
            ->schema([
                Textarea::make('reason')
                    ->label('Correction reason')
                    ->required()
                    ->minLength(UnlockMonthlyReportAction::REASON_MIN)
                    ->maxLength(UnlockMonthlyReportAction::REASON_MAX)
                    ->rows(4)
                    ->placeholder('e.g. GA4 organic sessions were entered incorrectly.')
                    ->helperText('Recorded permanently with the archived version and in the audit trail.'),
            ])
            ->authorize(fn (): bool => Gate::allows('unlock', $this->getReport()))
            ->action(function (array $data, Action $action): void {
                $report = $this->getReport();

                Gate::authorize('unlock', $report);

                $this->runDomain($action, fn () => app(UnlockMonthlyReportAction::class)->handle($report, $this->currentUser(), $data['reason'] ?? null), 'Report unlocked for correction. The previous final version has been preserved.');
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToReports')
                ->label('All reports')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('reports', ['record' => $this->getRecord()])),
            Action::make('preview')
                ->label(fn (): string => $this->getReport()->isFinal() ? 'View final report' : 'Preview report')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn (): string => $this->previewUrl(), shouldOpenInNewTab: true),
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->authorize(fn (): bool => Gate::allows('downloadPdf', $this->getReport()))
                ->url(fn (): string => $this->pdfUrl(), shouldOpenInNewTab: true),
            Action::make('markReady')
                ->label('Mark Ready for Review')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Mark this report Ready for Review')
                ->modalDescription('Readiness is re-checked now. Source data can still be corrected until a manager finalizes the report.')
                ->visible(fn (): bool => ! $this->getReport()->isReadyForReview())
                ->authorize(fn (): bool => Gate::allows('markReady', $this->getReport()))
                ->action(function (Action $action): void {
                    $this->runDomain($action, fn () => app(MarkReportReadyAction::class)->handle($this->getReport(), $this->currentUser()), 'Report marked Ready for Review');
                }),
            Action::make('finalize')
                ->label('Finalize Report')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Finalize this report')
                ->modalDescription("Finalizing will:\n• re-check readiness and generate the final PDF\n• preserve the report snapshot\n• lock this reporting period\n• prevent normal editing of monthly data")
                ->modalSubmitActionLabel('Finalize Report')
                ->authorize(fn (): bool => Gate::allows('finalize', $this->getReport()))
                ->action(function (Action $action): void {
                    $this->runDomain($action, fn () => app(FinalizeMonthlyReportAction::class)->handle($this->getReport(), $this->currentUser()), 'Report finalized and reporting period locked');
                }),
            Action::make('checkReadiness')
                ->label('Re-check readiness')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => ! $this->getReport()->isFinal())
                ->action(function (): void {
                    $readiness = app(SyncReportSectionStatusesAction::class)->handle($this->getReport());

                    Notification::make()
                        ->title($readiness->isReady() ? 'Readiness 100% — the report can be marked Ready for Review' : 'Readiness '.$readiness->percentage().'% — '.$readiness->label())
                        ->body($readiness->isReady() ? null : 'Missing: '.$readiness->missing()->map(fn (SectionReadiness $s): string => $s->title)->implode(', '))
                        ->color($readiness->isReady() ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }

    public function editExecutiveSummaryAction(): Action
    {
        return Action::make('editExecutiveSummary')
            ->label('Edit executive summary')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->size('sm')
            ->modalHeading('Executive summary')
            ->modalWidth('3xl')
            ->schema([
                Textarea::make('executive_summary')
                    ->label('Executive summary')
                    ->rows(10)
                    ->maxLength(UpdateMonthlyReportDraftAction::SUMMARY_MAX)
                    ->nullable()
                    ->helperText('Client-facing. A non-empty summary completes the Executive Summary section.'),
            ])
            ->fillForm(fn (): array => ['executive_summary' => $this->getReport()->executive_summary])
            ->authorize(fn (): bool => $this->canPrepare())
            ->action(function (array $data, Action $action): void {
                $report = $this->getReport();

                Gate::authorize('prepare', $report);

                $this->runDomain($action, function () use ($report, $data): void {
                    app(UpdateMonthlyReportDraftAction::class)->handle($report, $data);
                    app(SyncReportSectionStatusesAction::class)->handle($report);
                }, 'Executive summary saved');
            });
    }

    public function editSectionTextAction(): Action
    {
        return Action::make('editSectionText')
            ->label('Commentary')
            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
            ->size('xs')
            ->color('gray')
            ->link()
            ->modalHeading(fn (array $arguments): string => 'Commentary — '.($this->resolveSection($arguments)->title))
            ->modalWidth('2xl')
            ->schema([
                Textarea::make('custom_text')
                    ->label('Commentary')
                    ->rows(6)
                    ->maxLength(UpdateReportSectionTextAction::TEXT_MAX)
                    ->nullable()
                    ->helperText('Optional client-facing interpretation or context for this section. Numbers always come from the source data.'),
            ])
            ->fillForm(fn (array $arguments): array => ['custom_text' => $this->resolveSection($arguments)->custom_text])
            ->authorize(fn (): bool => $this->canPrepare())
            ->action(function (array $data, array $arguments, Action $action): void {
                $section = $this->resolveSection($arguments);

                Gate::authorize('prepare', $section->report);

                $this->runDomain($action, fn () => app(UpdateReportSectionTextAction::class)->handle($section, $data['custom_text'] ?? null), 'Commentary saved');
            });
    }

    public function editReviewNotesAction(): Action
    {
        return Action::make('editReviewNotes')
            ->label('Internal review notes')
            ->icon(Heroicon::OutlinedLockClosed)
            ->size('sm')
            ->color('gray')
            ->modalHeading('Internal review notes')
            ->modalDescription('For the team only. Never rendered into the client preview or PDF.')
            ->modalWidth('2xl')
            ->schema([
                Textarea::make('review_notes')
                    ->label('Review notes')
                    ->rows(6)
                    ->maxLength(UpdateReportReviewNotesAction::NOTES_MAX)
                    ->nullable(),
            ])
            ->fillForm(fn (): array => ['review_notes' => $this->getReport()->review_notes])
            ->authorize(fn (): bool => Gate::allows('reviewNotes', $this->getReport()))
            ->action(function (array $data, Action $action): void {
                $report = $this->getReport();

                Gate::authorize('reviewNotes', $report);

                $this->runDomain($action, fn () => app(UpdateReportReviewNotesAction::class)->handle($report, $data['review_notes'] ?? null), 'Review notes saved');
            });
    }

    /**
     * A section of THIS report only; crafted ids resolve to 404.
     */
    protected function resolveSection(array $arguments): MonthlyReportSection
    {
        return $this->getReport()->sections()->findOrFail((int) ($arguments['section'] ?? 0));
    }

    protected function runDomain(Action $action, callable $call, string $successTitle): void
    {
        try {
            $call();
        } catch (ReportNotReadyException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->body('Missing: '.$exception->readiness->missing()->map(fn (SectionReadiness $s): string => $s->title.' — '.$s->reason)->implode("\n"))
                ->danger()
                ->persistent()
                ->send();

            $action->halt();
        } catch (InvalidArgumentException|LockedMonthlyCycleException|PdfGenerationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
