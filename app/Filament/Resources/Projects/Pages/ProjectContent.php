<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Content\CreateContentItemAction;
use App\Actions\Content\SetContentStatusAction;
use App\Actions\Content\UpdateContentItemAction;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\MonthlyCycleStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\ContentItemForm;
use App\Models\ContentItem;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Targets\TargetProgress;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Project → Content: the selected month's Blogs Published progress against
 * that month's snapshotted target, plus the content work items with
 * Unscheduled and All views.
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403), the table runs through ContentItem::scopeAccessibleBy() and every
 * action is authorized by ContentItemPolicy / ProjectPolicy. Not a global
 * sidebar module. Presentation reads the existing records and services only.
 */
class ProjectContent extends ResourcePage implements HasTable
{
    use HasProjectWorkspace;
    use InteractsWithRecord;
    use InteractsWithTable;

    public const ALL = 'all';

    public const UNSCHEDULED = 'unscheduled';

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-content';

    /**
     * A monthly cycle id, "unscheduled" (project-level items) or "all".
     */
    public string $selectedView = self::ALL;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $requested = request()->query('view');

        if (in_array($requested, [self::ALL, self::UNSCHEDULED], true)) {
            $this->selectedView = $requested;
        } elseif ((int) $requested > 0 && $this->getProject()->monthlyCycles()->whereKey((int) $requested)->exists()) {
            $this->selectedView = (string) (int) $requested;
        } else {
            $this->selectedView = (string) ($this->defaultCycle()?->getKey() ?? self::ALL);
        }
    }

    public function updatedSelectedView(): void
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
        if (in_array($this->selectedView, [self::ALL, self::UNSCHEDULED], true)) {
            return null;
        }

        return $this->getProject()->monthlyCycles()->find((int) $this->selectedView);
    }

    public function isUnscheduledView(): bool
    {
        return $this->selectedView === self::UNSCHEDULED;
    }

    public function getBlogsProgress(): ?TargetProgress
    {
        $cycle = $this->getSelectedCycle();

        return $cycle ? app(TargetProgressService::class)->blogs($cycle) : null;
    }

    /**
     * Items per status in the current view (every status, in workflow order),
     * from one grouped query over the same scoped records as the table.
     *
     * @return array<string, int>
     */
    public function getWorkflowSummary(): array
    {
        $counts = $this->scopedQuery()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $summary = [];

        foreach (ContentStatus::cases() as $status) {
            $summary[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $summary;
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
        return $this->getSelectedCycle()?->getKey();
    }

    /**
     * The records of the current view: selected month, unscheduled, or all.
     *
     * @return Builder<ContentItem>
     */
    protected function scopedQuery(): Builder
    {
        return ContentItem::query()
            ->where('project_id', $this->getProject()->getKey())
            ->accessibleBy($this->currentUser())
            ->when($this->isUnscheduledView(), fn (Builder $query) => $query->unscheduled())
            ->when($this->getSelectedCycle(), fn (Builder $query, MonthlyCycle $cycle) => $query->where('monthly_cycle_id', $cycle->getKey()));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->scopedQuery()->with(['assignee', 'targetKeyword', 'monthlyCycle']))
            ->defaultSort('planned_publish_date')
            ->columns([
                TextColumn::make('title')
                    ->label('Content')
                    ->weight(FontWeight::SemiBold)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('published_url', 'like', "%{$search}%")
                            ->orWhereHas('targetKeyword', fn (Builder $keyword) => $keyword->where('keyword', 'like', "%{$search}%")),
                    ))
                    ->sortable()
                    ->description(fn (ContentItem $record): string => implode(' · ', array_filter([
                        $record->targetKeyword ? 'Target: '.$record->targetKeyword->keyword : 'No target keyword',
                        $this->selectedView === self::ALL ? ($record->monthlyCycle?->periodLabel() ?? 'Unscheduled') : null,
                    ]))),
                TextColumn::make('content_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('assignee.name')
                    ->label('Owner')
                    ->placeholder('Unassigned'),
                TextColumn::make('dates')
                    ->label('Dates')
                    ->state(fn (ContentItem $record): array => array_filter([
                        'Planned: '.($record->planned_publish_date?->format('j M Y') ?? '—'),
                        $record->published_at ? 'Published: '.$record->published_at->format('j M Y') : null,
                    ]))
                    ->listWithLineBreaks()
                    ->size('xs')
                    ->sortable(['planned_publish_date']),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->searchPlaceholder('Search title, keyword or URL')
            ->filters([
                SelectFilter::make('content_type')
                    ->label('Type')
                    ->options(ContentType::class),
                SelectFilter::make('status')
                    ->options(ContentStatus::class),
                SelectFilter::make('assigned_user_id')
                    ->label('Assignee')
                    ->options(fn (): array => ContentItemForm::assignableUsers($this->getProject())->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->link()
                    ->size('sm')
                    ->modalHeading('Edit content')
                    ->schema(fn (ContentItem $record): array => ContentItemForm::components($this->getProject(), $record))
                    ->fillForm(fn (ContentItem $record): array => ContentItemForm::fillFromItem($record))
                    ->authorize(fn (ContentItem $record): bool => Gate::allows('update', $record))
                    ->action(function (ContentItem $record, array $data, Action $action): void {
                        Gate::authorize('update', $record);

                        $this->runDomain($action, fn () => app(UpdateContentItemAction::class)->handle($record, ContentItemForm::attributesFromData($data)), 'Content updated');
                    }),
                Action::make('publish')
                    ->label('Publish')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->link()
                    ->size('sm')
                    ->modalHeading('Publish content')
                    ->modalDescription('Attribute the item to a reporting month and record where it was published.')
                    ->schema(fn (): array => [
                        Select::make('monthly_cycle_id')
                            ->label('Reporting month')
                            ->options(fn (): array => $this->getProject()->monthlyCycles()
                                ->where('status', '!=', MonthlyCycleStatus::Locked->value)
                                ->latestPeriodFirst()
                                ->get()
                                ->mapWithKeys(fn (MonthlyCycle $cycle): array => [$cycle->id => $cycle->periodLabel()])
                                ->all())
                            ->required()
                            ->native(false)
                            ->rule(Rule::exists('monthly_cycles', 'id')
                                ->where('project_id', $this->getProject()->getKey())
                                ->where('status', '!=', MonthlyCycleStatus::Locked->value)),
                        DateTimePicker::make('published_at')
                            ->label('Published date')
                            ->default(now())
                            ->seconds(false)
                            ->native(false)
                            ->required(),
                        TextInput::make('published_url')
                            ->label('Published URL')
                            ->url()
                            ->maxLength(500)
                            ->required(),
                    ])
                    ->fillForm(fn (ContentItem $record): array => [
                        'monthly_cycle_id' => $record->monthly_cycle_id ?? $this->defaultCycleIdForForms(),
                        'published_at' => now()->toDateTimeString(),
                        'published_url' => $record->published_url,
                    ])
                    ->visible(fn (ContentItem $record): bool => ! $record->isPublished() && $record->status !== ContentStatus::Cancelled)
                    ->authorize(fn (ContentItem $record): bool => Gate::allows('setStatus', $record))
                    ->action(function (ContentItem $record, array $data, Action $action): void {
                        Gate::authorize('setStatus', $record);

                        $this->runDomain($action, fn () => app(SetContentStatusAction::class)->handle($record, ContentStatus::Published, $data), 'Content published');
                    }),
                // Workflow steps and the status override live behind "•••" to keep rows compact.
                ActionGroup::make([
                    Action::make('advance')
                        ->label(fn (ContentItem $record): string => 'Mark '.($record->status->next()?->getLabel() ?? ''))
                        ->icon(Heroicon::OutlinedArrowRight)
                        ->color('info')
                        ->visible(fn (ContentItem $record): bool => $record->status->next() !== null && $record->status->next() !== ContentStatus::Published)
                        ->authorize(fn (ContentItem $record): bool => Gate::allows('setStatus', $record))
                        ->action(function (ContentItem $record, Action $action): void {
                            Gate::authorize('setStatus', $record);

                            $this->runDomain($action, fn () => app(SetContentStatusAction::class)->handle($record, $record->status->next()), 'Status updated');
                        }),
                    Action::make('setStatus')
                        ->label('Change status')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->modalHeading('Change status')
                        ->modalWidth('sm')
                        ->schema([
                            Select::make('status')
                                ->options(collect(ContentStatus::cases())
                                    ->reject(fn (ContentStatus $status): bool => $status === ContentStatus::Published)
                                    ->mapWithKeys(fn (ContentStatus $status): array => [$status->value => $status->getLabel()])
                                    ->all())
                                ->required()
                                ->native(false)
                                ->helperText('Use “Publish” to mark content published.'),
                        ])
                        ->fillForm(fn (ContentItem $record): array => ['status' => $record->isPublished() ? null : $record->status->value])
                        ->authorize(fn (ContentItem $record): bool => Gate::allows('setStatus', $record))
                        ->action(function (ContentItem $record, array $data, Action $action): void {
                            Gate::authorize('setStatus', $record);

                            $this->runDomain($action, fn () => app(SetContentStatusAction::class)->handle($record, $data['status']), 'Status updated');
                        }),
                ]),
            ])
            ->toolbarActions([])
            ->paginated([10, 25, 50])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(fn (): string => $this->isUnscheduledView() ? 'No unscheduled content' : 'No content planned yet')
            ->emptyStateDescription(fn (): string => $this->isUnscheduledView()
                ? 'Content not assigned to a reporting month will appear here.'
                : 'Add blogs, landing pages and other SEO content to track their progress through the month.')
            ->emptyStateActions([
                $this->addContentAction(Action::make('createFirstContent')),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addContentAction(Action::make('createContent')),
        ];
    }

    /**
     * The single "Add content" workflow (ContentItemForm + CreateContentItemAction),
     * used by the header and the empty state alike.
     */
    protected function addContentAction(Action $action): Action
    {
        return $action
            ->label('Add content')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add content')
            ->schema(fn (): array => ContentItemForm::components($this->getProject(), null, $this->defaultCycleIdForForms()))
            ->authorize(fn (): bool => Gate::allows('manageContent', $this->getProject()))
            ->action(function (array $data, Action $action): void {
                Gate::authorize('manageContent', $this->getProject());

                $this->runDomain($action, fn () => app(CreateContentItemAction::class)->handle($this->getProject(), ContentItemForm::attributesFromData($data)), 'Content added');
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
