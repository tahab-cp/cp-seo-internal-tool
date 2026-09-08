<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Keywords\CreateKeywordAction;
use App\Actions\Keywords\SetKeywordStatusAction;
use App\Actions\Keywords\UpdateKeywordAction;
use App\Actions\Rankings\RecordRankingSnapshotsAction;
use App\Enums\KeywordIntent;
use App\Enums\KeywordStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\KeywordForm;
use App\Filament\Resources\Projects\Schemas\RankingForms;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Project → Keywords: master data list with the derived latest rank and the
 * bulk "Update rankings" entry.
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403), the table runs through Keyword::scopeAccessibleBy() and every
 * action is authorized by KeywordPolicy / ProjectPolicy. Not a global
 * sidebar module.
 */
class ProjectKeywords extends ResourcePage implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-keywords';

    protected static ?string $title = 'Keywords';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
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

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    protected function defaultCycleId(): ?int
    {
        $cycles = $this->getProject()->monthlyCycles()->latestPeriodFirst()->get();
        $current = CyclePeriod::current();

        return ($cycles->first(fn (MonthlyCycle $cycle): bool => $cycle->period()->equals($current)) ?? $cycles->first())?->getKey();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Keyword::query()
                ->where('project_id', $this->getProject()->getKey())
                ->accessibleBy($this->currentUser())
                ->with(['targetPage', 'latestSnapshot']))
            ->defaultSort('keyword')
            ->columns([
                TextColumn::make('keyword')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('keyword', 'like', "%{$search}%")
                            ->orWhereHas('targetPage', fn (Builder $page) => $page
                                ->where('url', 'like', "%{$search}%")
                                ->orWhere('title', 'like', "%{$search}%")),
                    ))
                    ->sortable()
                    ->description(fn (Keyword $record): ?string => $record->location)
                    ->url(fn (Keyword $record): string => ProjectResource::getUrl('keyword', ['record' => $this->getRecord(), 'keyword' => $record])),
                TextColumn::make('targetPage.url')
                    ->label('Target page')
                    ->state(fn (Keyword $record): ?string => $record->targetPage?->displayName())
                    ->description(fn (Keyword $record): ?string => $record->targetPage?->url)
                    ->placeholder('—'),
                TextColumn::make('search_volume')
                    ->label('Volume')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('keyword_difficulty')
                    ->label('KD')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('search_intent')
                    ->label('Intent')
                    ->badge()
                    ->placeholder('—'),
                IconColumn::make('is_branded')
                    ->label('Branded')
                    ->boolean(),
                TextColumn::make('latest_rank')
                    ->label('Latest rank')
                    ->state(fn (Keyword $record): string => $record->latestSnapshot
                        ? $record->latestSnapshot->positionLabel()
                        : '—')
                    ->description(fn (Keyword $record): ?string => $record->latestSnapshot?->checked_at->format('j M Y')),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(KeywordStatus::class),
                SelectFilter::make('search_intent')
                    ->label('Intent')
                    ->options(KeywordIntent::class),
                TernaryFilter::make('is_branded')
                    ->label('Branded')
                    ->trueLabel('Branded')
                    ->falseLabel('Non-branded'),
                SelectFilter::make('target_page_id')
                    ->label('Target page')
                    ->options(fn (): array => $this->getProject()->pages()
                        ->orderBy('url')
                        ->get()
                        ->mapWithKeys(fn (Page $page): array => [$page->id => $page->displayName()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Keyword $record): string => ProjectResource::getUrl('keyword', ['record' => $this->getRecord(), 'keyword' => $record])),
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit keyword')
                    ->schema(fn (Keyword $record): array => KeywordForm::components($this->getProject(), $record))
                    ->fillForm(fn (Keyword $record): array => KeywordForm::fillFromKeyword($record))
                    ->authorize(fn (Keyword $record): bool => Gate::allows('update', $record))
                    ->action(function (Keyword $record, array $data, Action $action): void {
                        Gate::authorize('update', $record);

                        $this->runDomain($action, fn () => app(UpdateKeywordAction::class)->handle($record, $data), 'Keyword updated');
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
                    ->fillForm(fn (Keyword $record): array => ['status' => $record->status->value])
                    ->authorize(fn (Keyword $record): bool => Gate::allows('setStatus', $record))
                    ->action(function (Keyword $record, array $data): void {
                        Gate::authorize('setStatus', $record);

                        app(SetKeywordStatusAction::class)->handle($record, $data['status']);
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedMagnifyingGlass)
            ->emptyStateHeading('No keywords yet')
            ->emptyStateDescription('Add the search terms you track for this project, then use “Update rankings” each time you check positions.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProject')
                ->label('Back to project')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('view', ['record' => $this->getRecord()])),
            Action::make('createKeyword')
                ->label('Add keyword')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Add keyword')
                ->schema(fn (): array => KeywordForm::components($this->getProject()))
                ->authorize(fn (): bool => Gate::allows('manageKeywords', $this->getProject()))
                ->action(function (array $data, Action $action): void {
                    Gate::authorize('manageKeywords', $this->getProject());

                    $this->runDomain($action, fn () => app(CreateKeywordAction::class)->handle($this->getProject(), $data), 'Keyword added');
                }),
            Action::make('updateRankings')
                ->label('Update rankings')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('primary')
                ->modalHeading('Update rankings')
                ->modalDescription('One observation per active keyword. Leave a position empty for “Not ranking”. Re-entering the same moment and source in the same reporting month updates that observation; a different month is rejected.')
                ->modalWidth('4xl')
                ->schema(fn (): array => RankingForms::bulkComponents($this->getProject(), $this->defaultCycleId()))
                ->fillForm(fn (): array => [
                    'monthly_cycle_id' => $this->defaultCycleId(),
                    'checked_at' => now()->toDateTimeString(),
                    'source' => 'manual',
                    'rows' => RankingForms::bulkRows($this->getProject()),
                ])
                ->authorize(fn (): bool => Gate::allows('recordRankings', $this->getProject()))
                ->visible(fn (): bool => $this->getProject()->keywords()->active()->exists())
                ->action(function (array $data, Action $action): void {
                    Gate::authorize('recordRankings', $this->getProject());

                    $this->runDomain($action, function () use ($data): string {
                        $snapshots = app(RecordRankingSnapshotsAction::class)->handle(
                            $this->getProject(),
                            [
                                'monthly_cycle_id' => $data['monthly_cycle_id'] ?? null,
                                'checked_at' => $data['checked_at'] ?? null,
                                'source' => $data['source'] ?? null,
                            ],
                            array_values($data['rows'] ?? []),
                        );

                        return $snapshots->count().' ranking observation(s) recorded';
                    });
                }),
        ];
    }

    /**
     * Run a domain call and turn its validation/lock exceptions into a
     * notification instead of an error page.
     */
    protected function runDomain(Action $action, callable $call, ?string $successTitle = null): void
    {
        try {
            $result = $call();
        } catch (InvalidArgumentException|LockedMonthlyCycleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();
        }

        Notification::make()->title($successTitle ?? (is_string($result) ? $result : 'Saved'))->success()->send();
    }
}
