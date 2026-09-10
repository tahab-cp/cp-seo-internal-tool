<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Pages\CreatePageAction;
use App\Actions\Pages\SetPageStatusAction;
use App\Actions\Pages\UpdatePageAction;
use App\Enums\PageStatus;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\PageForm;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Targets\TargetProgress;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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

/**
 * Project → Pages: master data list plus the selected month's
 * "Pages Optimised" progress (actual vs. that cycle's snapshotted target).
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403), the table runs through Page::scopeAccessibleBy(), every action is
 * authorized by PagePolicy / ProjectPolicy and all writes go through the
 * page actions. Not registered in the global sidebar.
 */
class ProjectPages extends ResourcePage implements HasTable
{
    use HasProjectWorkspace;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-pages';

    protected static ?string $title = 'Pages';

    public ?int $selectedCycleId = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

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

    /**
     * Pages Optimised for the selected cycle, from that cycle's own records
     * and target snapshot.
     */
    public function getPagesOptimisedProgress(): ?TargetProgress
    {
        $cycle = $this->getSelectedCycle();

        return $cycle ? app(TargetProgressService::class)->pagesOptimised($cycle) : null;
    }

    /**
     * Pages the project tracks (master data count, all statuses).
     */
    public function getPagesTracked(): int
    {
        return $this->getProject()->pages()->count();
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
            ->query(fn (): Builder => Page::query()
                ->where('project_id', $this->getProject()->getKey())
                ->accessibleBy($this->currentUser())
                ->withMax('optimizations', 'optimized_at')
                // One grouped sub-select, no per-row query: optimisation events in the selected month.
                ->withCount(['optimizations as month_optimizations_count' => fn (Builder $query) => $query->where('monthly_cycle_id', $this->selectedCycleId ?? 0)]))
            ->defaultSort('url')
            ->columns([
                TextColumn::make('title')
                    ->label('Page')
                    ->state(fn (Page $record): string => $record->displayName())
                    ->description(fn (Page $record): string => $this->pathOf($record))
                    ->weight('semibold')
                    ->searchable(['title', 'url'])
                    ->sortable()
                    ->url(fn (Page $record): string => ProjectResource::getUrl('page', ['record' => $this->getRecord(), 'page' => $record])),
                TextColumn::make('page_type')
                    ->label('Type')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('month_optimizations_count')
                    ->label('This month')
                    ->state(fn (Page $record): ?int => $record->month_optimizations_count > 0 ? (int) $record->month_optimizations_count : null)
                    ->placeholder('—')
                    ->alignRight()
                    ->tooltip('Optimisation events recorded in the selected reporting month'),
                TextColumn::make('optimizations_max_optimized_at')
                    ->label('Last optimised')
                    ->dateTime('j M Y')
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PageStatus::class),
                SelectFilter::make('page_type')
                    ->label('Page type')
                    ->options(fn (): array => $this->getProject()->pages()
                        ->whereNotNull('page_type')
                        ->distinct()
                        ->orderBy('page_type')
                        ->pluck('page_type', 'page_type')
                        ->all()),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Page $record): string => ProjectResource::getUrl('page', ['record' => $this->getRecord(), 'page' => $record])),
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit page')
                    ->schema(fn (Page $record): array => PageForm::components($this->getProject(), $record))
                    ->fillForm(fn (Page $record): array => PageForm::fillFromPage($record))
                    ->authorize(fn (Page $record): bool => Gate::allows('update', $record))
                    ->action(function (Page $record, array $data): void {
                        Gate::authorize('update', $record);

                        app(UpdatePageAction::class)->handle($record, $data);

                        Notification::make()->title('Page updated')->success()->send();
                    }),
                ActionGroup::make([
                    Action::make('open')
                        ->label('Open live page')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Page $record): string => $record->url)
                        ->openUrlInNewTab(),
                    Action::make('setStatus')
                        ->label('Change status')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->modalHeading('Change page status')
                        ->modalWidth('sm')
                        ->schema([
                            Select::make('status')
                                ->options(PageStatus::class)
                                ->required()
                                ->native(false),
                        ])
                        ->fillForm(fn (Page $record): array => ['status' => $record->status->value])
                        ->authorize(fn (Page $record): bool => Gate::allows('setStatus', $record))
                        ->action(function (Page $record, array $data): void {
                            Gate::authorize('setStatus', $record);

                            app(SetPageStatusAction::class)->handle($record, $data['status']);
                        }),
                    Action::make('markRemoved')
                        ->label('Mark removed')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('The page stays in the project as history and keeps all optimisation records. It can be reactivated later.')
                        ->visible(fn (Page $record): bool => ! $record->isRemoved())
                        ->authorize(fn (Page $record): bool => Gate::allows('setStatus', $record))
                        ->action(function (Page $record): void {
                            Gate::authorize('setStatus', $record);

                            app(SetPageStatusAction::class)->handle($record, PageStatus::Removed);
                        }),
                ])->tooltip('More'),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading('No pages added yet')
            ->emptyStateDescription('Add the important pages from this website so you can record SEO optimisation work against them.')
            ->emptyStateActions([
                // The same Add page form and workflow as the header action, offered where the list is empty.
                $this->addPageAction(Action::make('createFirstPage')->label('Add first page')),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addPageAction(Action::make('createPage')->label('Add page')),
        ];
    }

    /**
     * The single "Add page" workflow: PageForm + CreatePageAction, governed
     * by the project's managePages ability.
     */
    protected function addPageAction(Action $action): Action
    {
        return $action
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->modalHeading('Add page')
            ->schema(fn (): array => PageForm::components($this->getProject()))
            ->authorize(fn (): bool => Gate::allows('managePages', $this->getProject()))
            ->action(function (array $data): void {
                Gate::authorize('managePages', $this->getProject());

                app(CreatePageAction::class)->handle($this->getProject(), $data);

                Notification::make()->title('Page added')->success()->send();
            });
    }

    /**
     * The path shown under the page title (host + path when the page lives
     * on another host than the project website).
     */
    protected function pathOf(Page $record): string
    {
        $path = $record->path ?: (parse_url($record->url, PHP_URL_PATH) ?: '/');
        $host = strtolower((string) parse_url($record->url, PHP_URL_HOST));
        $projectHost = strtolower((string) parse_url((string) $this->getProject()->website_url, PHP_URL_HOST));

        $display = $host !== '' && preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $projectHost) ? $host.$path : $path;

        return mb_strlen($display) > 70 ? mb_substr($display, 0, 67).'…' : $display;
    }
}
