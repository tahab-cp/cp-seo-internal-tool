<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Keywords\SetKeywordStatusAction;
use App\Actions\Keywords\UpdateKeywordAction;
use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Actions\Rankings\UpdateRankingSnapshotAction;
use App\Enums\KeywordStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\KeywordForm;
use App\Filament\Resources\Projects\Schemas\RankingForms;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\User;
use App\Services\Rankings\RankingMovementService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Rankings\MonthlyRankingSummary;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
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
 * observation history with Record / correct actions.
 */
class ProjectKeywordDetail extends ResourcePage implements HasTable
{
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
        return $this->getKeyword()->keyword;
    }

    public function getSubheading(): ?string
    {
        $keyword = $this->getKeyword();

        return $this->getProject()->name.' — '.$keyword->displayLocation();
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
            ->with('targetPage')
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
                    ->dateTime('j M Y H:i')
                    ->sortable(),
                TextColumn::make('monthlyCycle.year')
                    ->label('Reporting month')
                    ->state(fn (RankingSnapshot $record): string => $record->monthlyCycle?->periodLabel() ?? '—')
                    ->badge()
                    ->color(fn (RankingSnapshot $record): string => $record->isLocked() ? 'gray' : 'info'),
                TextColumn::make('position')
                    ->state(fn (RankingSnapshot $record): string => $record->positionLabel())
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('ranking_url')
                    ->label('Ranking URL')
                    ->limit(50)
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
                    ->modalHeading('Correct ranking observation')
                    ->schema(fn (): array => RankingForms::singleComponents($this->getProject()))
                    ->fillForm(fn (RankingSnapshot $record): array => RankingForms::fillFromSnapshot($record))
                    ->authorize(fn (RankingSnapshot $record): bool => Gate::allows('update', $record))
                    ->action(function (RankingSnapshot $record, array $data, Action $action): void {
                        Gate::authorize('update', $record);

                        $this->runDomain($action, fn () => app(UpdateRankingSnapshotAction::class)->handle($record, $data), 'Observation corrected');
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedChartBar)
            ->emptyStateHeading('No ranking snapshots yet')
            ->emptyStateDescription('Use “Record ranking” here, or “Update rankings” on the keywords list to enter positions for every keyword at once.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToKeywords')
                ->label('All keywords')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
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
            Action::make('setStatus')
                ->label('Status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->modalHeading('Change keyword status')
                ->modalWidth('sm')
                ->schema([
                    Select::make('status')
                        ->options(KeywordStatus::class)
                        ->required()
                        ->native(false),
                ])
                ->fillForm(fn (): array => ['status' => $this->getKeyword()->status->value])
                ->authorize(fn (): bool => Gate::allows('setStatus', $this->getKeyword()))
                ->action(function (array $data): void {
                    Gate::authorize('setStatus', $this->getKeyword());

                    app(SetKeywordStatusAction::class)->handle($this->getKeyword(), $data['status']);
                }),
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

        Notification::make()->title($successTitle)->success()->send();
    }
}
