<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Content\CreateContentItemAction;
use App\Actions\Content\SetContentStatusAction;
use App\Actions\Content\UpdateContentItemAction;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\MonthlyCycleStatus;
use App\Exceptions\LockedMonthlyCycleException;
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
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
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
 * sidebar module.
 */
class ProjectContent extends ResourcePage implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    public const ALL = 'all';

    public const UNSCHEDULED = 'unscheduled';

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-content';

    protected static ?string $title = 'Content';

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

    public function getBlogsProgress(): ?TargetProgress
    {
        $cycle = $this->getSelectedCycle();

        return $cycle ? app(TargetProgressService::class)->blogs($cycle) : null;
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

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ContentItem::query()
                ->where('project_id', $this->getProject()->getKey())
                ->accessibleBy($this->currentUser())
                ->when($this->selectedView === self::UNSCHEDULED, fn (Builder $query) => $query->unscheduled())
                ->when($this->getSelectedCycle(), fn (Builder $query, MonthlyCycle $cycle) => $query->where('monthly_cycle_id', $cycle->getKey()))
                ->with(['assignee', 'targetKeyword', 'monthlyCycle']))
            ->defaultSort('planned_publish_date')
            ->columns([
                TextColumn::make('title')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('published_url', 'like', "%{$search}%")
                            ->orWhereHas('targetKeyword', fn (Builder $keyword) => $keyword->where('keyword', 'like', "%{$search}%")),
                    ))
                    ->sortable()
                    ->description(fn (ContentItem $record): string => $record->monthlyCycle?->periodLabel() ?? 'Unscheduled'),
                TextColumn::make('content_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('targetKeyword.keyword')
                    ->label('Target keyword')
                    ->placeholder('—'),
                TextColumn::make('assignee.name')
                    ->label('Assignee')
                    ->placeholder('Unassigned'),
                TextColumn::make('planned_publish_date')
                    ->label('Planned')
                    ->date('j M Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->placeholder('—')
                    ->url(fn (ContentItem $record): ?string => $record->published_url)
                    ->openUrlInNewTab(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
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
                    ->modalHeading('Edit content')
                    ->schema(fn (ContentItem $record): array => ContentItemForm::components($this->getProject(), $record))
                    ->fillForm(fn (ContentItem $record): array => ContentItemForm::fillFromItem($record))
                    ->authorize(fn (ContentItem $record): bool => Gate::allows('update', $record))
                    ->action(function (ContentItem $record, array $data, Action $action): void {
                        Gate::authorize('update', $record);

                        $this->runDomain($action, fn () => app(UpdateContentItemAction::class)->handle($record, ContentItemForm::attributesFromData($data)), 'Content updated');
                    }),
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
                Action::make('publish')
                    ->label('Publish')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
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
                Action::make('setStatus')
                    ->label('Status')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
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
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading('No content yet')
            ->emptyStateDescription('Plan blogs and pages here, track them through writing and review, and publish them against a reporting month. Only published blogs count toward the monthly target.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProject')
                ->label('Back to project')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('view', ['record' => $this->getRecord()])),
            Action::make('createContent')
                ->label('Add content')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Add content')
                ->schema(fn (): array => ContentItemForm::components($this->getProject(), null, $this->defaultCycleIdForForms()))
                ->authorize(fn (): bool => Gate::allows('manageContent', $this->getProject()))
                ->action(function (array $data, Action $action): void {
                    Gate::authorize('manageContent', $this->getProject());

                    $this->runDomain($action, fn () => app(CreateContentItemAction::class)->handle($this->getProject(), ContentItemForm::attributesFromData($data)), 'Content added');
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
