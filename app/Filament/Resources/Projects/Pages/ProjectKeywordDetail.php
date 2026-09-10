<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Keywords\SetKeywordStatusAction;
use App\Actions\Keywords\UpdateKeywordAction;
use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Actions\Rankings\UpdateRankingSnapshotAction;
use App\Enums\KeywordStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\KeywordForm;
use App\Filament\Resources\Projects\Schemas\RankingForms;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\Rankings\RankingMovementService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Rankings\MonthlyRankingSummary;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/**
 * Project → Keywords → one keyword: master data, the selected month's
 * ranking summary (month start → latest, derived movement) and the full
 * observation history with Record / correct actions. Presentation reads
 * the existing records and services only.
 */
class ProjectKeywordDetail extends ResourcePage implements HasTable
{
    use HasProjectWorkspace;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-keyword-detail';

    #[Locked]
    public int $keywordId;

    public ?int $selectedCycleId = null;

    public function mount(int|string $record, int|string $keyword): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->keywordId = Keyword::query()
            ->where('project_id', $this->getRecord()->getKey())
            ->accessibleBy($this->currentUser())
            ->findOrFail((int) $keyword)
            ->getKey();

        $requested = request()->integer('cycle');

        $this->selectedCycleId = $requested > 0 && $this->getProject()->monthlyCycles()->whereKey($requested)->exists()
            ? $requested
            : $this->defaultCycle()?->getKey();
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

    public function getKeyword(): Keyword
    {
        return Keyword::query()
            ->where('project_id', $this->getProject()->getKey())
            ->with(['targetPage', 'latestSnapshot'])
            ->findOrFail($this->keywordId);
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
        return $this->selectedCycleId
            ? $this->getProject()->monthlyCycles()->find($this->selectedCycleId)
            : null;
    }

    public function getMonthlySummary(): ?MonthlyRankingSummary
    {
        $cycle = $this->getSelectedCycle();

        return $cycle ? app(RankingMovementService::class)->monthlySummary($this->getKeyword(), $cycle) : null;
    }

    /**
     * Presentation of the target page's path: "/interior-design", or
     * "host/path" when the page lives on another host than the project site.
     */
    public function targetPagePath(Page $page): string
    {
        $path = $page->path ?: (parse_url($page->url, PHP_URL_PATH) ?: '/');
        $host = strtolower((string) parse_url($page->url, PHP_URL_HOST));
        $projectHost = strtolower((string) parse_url((string) $this->getProject()->website_url, PHP_URL_HOST));

        $display = $host !== '' && preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $projectHost) ? $host.$path : $path;

        return mb_strlen($display) > 70 ? mb_substr($display, 0, 67).'…' : $display;
    }

    /**
     * The page-detail URL for the target page while it is still reachable in the project.
     */
    public function targetPageUrl(Page $page): ?string
    {
        return $page->trashed() ? null : ProjectResource::getUrl('page', ['record' => $this->getRecord(), 'page' => $page]);
    }

    protected function defaultCycle(): ?MonthlyCycle
    {
        $cycles = $this->getCycles();
        $current = CyclePeriod::current();

        return $cycles->first(fn (MonthlyCycle $cycle): bool => $cycle->period()->equals($current))
            ?? $cycles->first();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => RankingSnapshot::query()
                ->where('keyword_id', $this->keywordId)
                ->accessibleBy($this->currentUser())
                ->with('monthlyCycle'))
            ->defaultSort('checked_at', 'desc')
            ->columns([
                TextColumn::make('checked_at')
                    ->label('Checked at')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),
                TextColumn::make('monthlyCycle.year')
                    ->label('Reporting month')
                    ->state(fn (RankingSnapshot $record): string => $record->monthlyCycle?->periodLabel() ?? '—')
                    ->description(fn (RankingSnapshot $record): ?string => $record->isLocked() ? 'Locked · read-only' : null)
                    ->badge()
                    ->color(fn (RankingSnapshot $record): string => $record->isLocked() ? 'gray' : 'info'),
                TextColumn::make('position')
                    ->state(fn (RankingSnapshot $record): string => $record->positionLabel())
                    ->badge(fn (RankingSnapshot $record): bool => ! $record->isRanking())
                    ->color(fn (RankingSnapshot $record): ?string => $record->isRanking() ? null : 'gray')
                    ->weight('bold')
                    ->size(TextSize::Large)
                    ->sortable(),
                TextColumn::make('ranking_url')
                    ->label('Ranking URL')
                    ->state(fn (RankingSnapshot $record): ?string => $record->ranking_url ? preg_replace('#^https?://(www\.)?#i', '', $record->ranking_url) : null)
                    ->limit(40)
                    ->tooltip(fn (RankingSnapshot $record): ?string => $record->ranking_url)
                    ->placeholder('—')
                    ->url(fn (RankingSnapshot $record): ?string => $record->ranking_url)
                    ->openUrlInNewTab(),
                TextColumn::make('source')
                    ->badge(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Correct')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->link()
                    ->size('sm')
                    ->modalHeading('Correct ranking check')
                    ->schema(fn (): array => RankingForms::singleComponents($this->getProject()))
                    ->fillForm(fn (RankingSnapshot $record): array => RankingForms::fillFromSnapshot($record))
                    ->authorize(fn (RankingSnapshot $record): bool => Gate::allows('update', $record))
                    ->action(function (RankingSnapshot $record, array $data, Action $action): void {
                        Gate::authorize('update', $record);

                        $this->runDomain($action, fn () => app(UpdateRankingSnapshotAction::class)->handle($record, $data), 'Observation corrected');
                    }),
            ])
            ->toolbarActions([])
            ->paginated([10, 25, 50])
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading('No ranking history yet')
            ->emptyStateDescription('Record the first ranking check for this keyword to start tracking movement.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToKeywords')
                ->label('All keywords')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->link()
                ->url(fn (): string => ProjectResource::getUrl('keywords', ['record' => $this->getRecord()])),
            Action::make('recordRanking')
                ->label('Record ranking')
                ->icon(Heroicon::OutlinedChartBar)
                ->modalHeading('Record ranking')
                ->schema(fn (): array => RankingForms::singleComponents($this->getProject(), $this->selectedCycleId))
                ->authorize(fn (): bool => Gate::allows('recordRankings', $this->getProject()))
                ->action(function (array $data, Action $action): void {
                    Gate::authorize('recordRankings', $this->getProject());

                    $this->runDomain($action, fn () => app(RecordRankingSnapshotAction::class)->handle(
                        $this->getProject(),
                        $data + ['keyword_id' => $this->keywordId],
                    ), 'Ranking recorded');
                }),
            Action::make('editKeyword')
                ->label('Edit keyword')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->modalHeading('Edit keyword')
                ->schema(fn (): array => KeywordForm::components($this->getProject(), $this->getKeyword()))
                ->fillForm(fn (): array => KeywordForm::fillFromKeyword($this->getKeyword()))
                ->authorize(fn (): bool => Gate::allows('update', $this->getKeyword()))
                ->action(function (array $data, Action $action): void {
                    Gate::authorize('update', $this->getKeyword());

                    $this->runDomain($action, fn () => app(UpdateKeywordAction::class)->handle($this->getKeyword(), $data), 'Keyword updated');
                }),
            // Status changes live behind "More" so they never compete with Record ranking.
            // Each entry is the existing status workflow (SetKeywordStatusAction + setStatus ability).
            ActionGroup::make([
                $this->statusAction('activate', KeywordStatus::Active, 'Activate', Heroicon::OutlinedPlayCircle, 'success'),
                $this->statusAction('pause', KeywordStatus::Paused, 'Pause', Heroicon::OutlinedPauseCircle, 'warning'),
                $this->statusAction('archive', KeywordStatus::Archived, 'Archive', Heroicon::OutlinedArchiveBox, 'gray')
                    ->modalDescription('Archiving stops tracking. The keyword and all of its ranking history remain.'),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisHorizontal)->color('gray')->button(),
        ];
    }

    /**
     * One status transition, shown only while the keyword is not already in that status.
     */
    protected function statusAction(string $name, KeywordStatus $status, string $label, Heroicon $icon, string $color): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalHeading($label.' keyword')
            ->visible(fn (): bool => $this->getKeyword()->status !== $status)
            ->authorize(fn (): bool => Gate::allows('setStatus', $this->getKeyword()))
            ->action(function () use ($status): void {
                Gate::authorize('setStatus', $this->getKeyword());

                app(SetKeywordStatusAction::class)->handle($this->getKeyword(), $status);

                Notification::make()->title('Keyword '.strtolower($status->getLabel()))->success()->send();
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
