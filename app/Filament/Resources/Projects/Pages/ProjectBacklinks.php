<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\Backlinks\SetBacklinkStatusAction;
use App\Actions\Backlinks\UpdateBacklinkAction;
use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\ImportType;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\BacklinkForm;
use App\Models\Backlink;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Targets\TargetProgress;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Project → Backlinks: the selected month's live-backlink and guest-post
 * progress (against that month's snapshotted targets), a live type
 * breakdown, and the backlink records with an All-time view.
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403), the table runs through Backlink::scopeAccessibleBy() and every
 * action is authorized by BacklinkPolicy / ProjectPolicy. Not a global
 * sidebar module. Presentation reads the existing records and services only.
 */
class ProjectBacklinks extends ResourcePage implements HasTable
{
    use HasProjectWorkspace;
    use InteractsWithRecord;
    use InteractsWithTable;

    public const ALL_TIME = 'all';

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-backlinks';

    /**
     * A monthly cycle id, or "all" for the all-time view.
     */
    public string $selectedCycle = self::ALL_TIME;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $requested = request()->query('cycle');

        if ($requested === self::ALL_TIME) {
            $this->selectedCycle = self::ALL_TIME;
        } elseif ((int) $requested > 0 && $this->getProject()->monthlyCycles()->whereKey((int) $requested)->exists()) {
            $this->selectedCycle = (string) (int) $requested;
        } else {
            $this->selectedCycle = (string) ($this->defaultCycle()?->getKey() ?? self::ALL_TIME);
        }
    }

    public function updatedSelectedCycle(): void
    {
        $this->resetTable();
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
        if ($this->selectedCycle === self::ALL_TIME) {
            return null;
        }

        return $this->getProject()->monthlyCycles()->find((int) $this->selectedCycle);
    }

    public function getBacklinksProgress(): ?TargetProgress
    {
        $cycle = $this->getSelectedCycle();

        return $cycle ? app(TargetProgressService::class)->backlinks($cycle) : null;
    }

    public function getGuestPostsProgress(): ?TargetProgress
    {
        $cycle = $this->getSelectedCycle();

        return $cycle ? app(TargetProgressService::class)->guestPosts($cycle) : null;
    }

    /**
     * Live links per type for the selected month (labels => counts, non-zero only).
     *
     * @return array<string, int>
     */
    public function getLiveTypeBreakdown(): array
    {
        $cycle = $this->getSelectedCycle();

        if ($cycle === null) {
            return [];
        }

        $breakdown = [];

        foreach (app(TargetProgressService::class)->liveBacklinkTypeBreakdown($cycle) as $type => $count) {
            if ($count > 0) {
                $breakdown[BacklinkType::from($type)->getLabel()] = $count;
            }
        }

        return $breakdown;
    }

    /**
     * Records in the current view (selected month, or all time), for the table summary line.
     */
    public function getRecordCount(): int
    {
        return Backlink::query()
            ->where('project_id', $this->getProject()->getKey())
            ->accessibleBy($this->currentUser())
            ->when($this->getSelectedCycle(), fn (Builder $query, MonthlyCycle $cycle) => $query->where('monthly_cycle_id', $cycle->getKey()))
            ->count();
    }

    protected function defaultCycle(): ?MonthlyCycle
    {
        $cycles = $this->getCycles();
        $current = CyclePeriod::current();

        return $cycles->first(fn (MonthlyCycle $cycle): bool => $cycle->period()->equals($current))
            ?? $cycles->first();
    }

    protected function defaultCycleIdForForms(): ?int
    {
        return $this->getSelectedCycle()?->getKey() ?? $this->defaultCycle()?->getKey();
    }

    /**
     * Display form of a URL: scheme and "www." stripped.
     */
    protected function displayUrl(?string $url): ?string
    {
        return $url ? preg_replace('#^https?://(www\.)?#i', '', $url) : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Backlink::query()
                ->where('project_id', $this->getProject()->getKey())
                ->accessibleBy($this->currentUser())
                ->when($this->getSelectedCycle(), fn (Builder $query, MonthlyCycle $cycle) => $query->where('monthly_cycle_id', $cycle->getKey()))
                ->with('monthlyCycle'))
            ->defaultSort('published_date', 'desc')
            ->columns([
                TextColumn::make('published_date')
                    ->label('Published')
                    ->date('j M Y')
                    ->sortable()
                    ->placeholder(fn (Backlink $record): string => in_array($record->status, [BacklinkStatus::Planned, BacklinkStatus::Submitted], true) ? 'Not published' : '—')
                    ->description(fn (Backlink $record): ?string => $this->getSelectedCycle() ? null : $record->monthlyCycle?->periodLabel()),
                TextColumn::make('published_url')
                    ->label('Link')
                    ->state(fn (Backlink $record): ?string => $this->displayUrl($record->published_url))
                    ->limit(45)
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Backlink $record): ?string => $record->anchor_text ? 'Anchor: '.Str::limit($record->anchor_text, 40) : null)
                    ->tooltip(fn (Backlink $record): string => $record->published_url)
                    ->url(fn (Backlink $record): string => $record->published_url)
                    ->openUrlInNewTab()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('published_url', 'like', "%{$search}%")
                            ->orWhere('anchor_text', 'like', "%{$search}%")
                            ->orWhere('target_url', 'like', "%{$search}%"),
                    )),
                TextColumn::make('target_url')
                    ->label('Target')
                    ->state(fn (Backlink $record): ?string => $this->displayUrl($record->target_url))
                    ->limit(40)
                    ->color('gray')
                    ->tooltip(fn (Backlink $record): ?string => $record->target_url)
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('metrics')
                    ->label('Metrics')
                    ->state(fn (Backlink $record): array => [
                        'DA '.($record->domain_authority ?? '—'),
                        'DR '.($record->domain_rating ?? '—'),
                        'Spam '.($record->spam_score ?? '—'),
                    ])
                    ->listWithLineBreaks()
                    ->size('xs')
                    ->sortable(['domain_authority']),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->searchPlaceholder('Search link, anchor or target')
            ->filters([
                SelectFilter::make('type')
                    ->options(BacklinkType::class),
                SelectFilter::make('status')
                    ->options(BacklinkStatus::class),
                Filter::make('domain_authority')
                    ->label('DA range')
                    ->schema([
                        TextInput::make('da_min')->label('DA from')->numeric()->integer()->minValue(0)->maxValue(100),
                        TextInput::make('da_max')->label('DA to')->numeric()->integer()->minValue(0)->maxValue(100),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['da_min'] ?? null), fn (Builder $q) => $q->where('domain_authority', '>=', (int) $data['da_min']))
                        ->when(filled($data['da_max'] ?? null), fn (Builder $q) => $q->where('domain_authority', '<=', (int) $data['da_max']))),
                Filter::make('domain_rating')
                    ->label('DR range')
                    ->schema([
                        TextInput::make('dr_min')->label('DR from')->numeric()->integer()->minValue(0)->maxValue(100),
                        TextInput::make('dr_max')->label('DR to')->numeric()->integer()->minValue(0)->maxValue(100),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['dr_min'] ?? null), fn (Builder $q) => $q->where('domain_rating', '>=', (int) $data['dr_min']))
                        ->when(filled($data['dr_max'] ?? null), fn (Builder $q) => $q->where('domain_rating', '<=', (int) $data['dr_max']))),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->link()
                    ->size('sm')
                    ->modalHeading('Edit backlink')
                    ->schema(fn (): array => BacklinkForm::components($this->getProject()))
                    ->fillForm(fn (Backlink $record): array => BacklinkForm::fillFromBacklink($record))
                    ->authorize(fn (Backlink $record): bool => Gate::allows('update', $record))
                    ->action(function (Backlink $record, array $data, Action $action): void {
                        Gate::authorize('update', $record);

                        $this->runDomain($action, fn () => app(UpdateBacklinkAction::class)->handle($record, $data), 'Backlink updated');
                    }),
                ActionGroup::make([
                    Action::make('setStatus')
                        ->label('Change status')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->modalHeading('Change backlink status')
                        ->modalWidth('sm')
                        ->schema([
                            Select::make('status')
                                ->options(BacklinkStatus::class)
                                ->required()
                                ->native(false),
                        ])
                        ->fillForm(fn (Backlink $record): array => ['status' => $record->status->value])
                        ->authorize(fn (Backlink $record): bool => Gate::allows('setStatus', $record))
                        ->action(function (Backlink $record, array $data, Action $action): void {
                            Gate::authorize('setStatus', $record);

                            $this->runDomain($action, fn () => app(SetBacklinkStatusAction::class)->handle($record, $data['status']), 'Status updated');
                        }),
                ]),
            ])
            ->toolbarActions([])
            ->paginated([10, 25, 50])
            ->emptyStateIcon(Heroicon::OutlinedLink)
            ->emptyStateHeading('No backlinks recorded yet')
            ->emptyStateDescription(fn (): string => $this->getSelectedCycle()
                ? 'Add link-building work for this reporting month to track progress and include it in the monthly report.'
                : 'Add link-building work to track progress and include it in the monthly report.')
            ->emptyStateActions([
                $this->addBacklinkAction(Action::make('createFirstBacklink')->label('Add backlink')),
                $this->importCsvAction(Action::make('importCsvEmpty')),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->importCsvAction(Action::make('importCsv')),
            $this->addBacklinkAction(Action::make('createBacklink')->label('Add backlink')),
        ];
    }

    /**
     * The single "Add backlink" workflow (BacklinkForm + CreateBacklinkAction),
     * used by the header and the empty state alike.
     */
    protected function addBacklinkAction(Action $action): Action
    {
        return $action
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add backlink')
            ->schema(fn (): array => BacklinkForm::components($this->getProject(), $this->defaultCycleIdForForms()))
            ->authorize(fn (): bool => Gate::allows('manageBacklinks', $this->getProject()))
            ->action(function (array $data, Action $action): void {
                Gate::authorize('manageBacklinks', $this->getProject());

                $this->runDomain(
                    $action,
                    fn () => app(CreateBacklinkAction::class)->handle($this->getProject(), $data, $this->currentUser()),
                    'Backlink added',
                );
            });
    }

    protected function importCsvAction(Action $action): Action
    {
        return $action
            ->label('Import CSV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->url(fn (): string => ImportBatchResource::getUrl('create', ['project' => $this->getRecord()->getKey(), 'type' => ImportType::Backlinks->value]));
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
