<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Pages\CreatePageAction;
use App\Actions\Pages\SetPageStatusAction;
use App\Actions\Pages\UpdatePageAction;
use App\Enums\PageStatus;
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
                ->withMax('optimizations', 'optimized_at'))
            ->defaultSort('url')
            ->columns([
                TextColumn::make('title')
                    ->label('Page')
                    ->state(fn (Page $record): string => $record->displayName())
                    ->searchable(['title', 'url'])
                    ->sortable()
                    ->url(fn (Page $record): string => ProjectResource::getUrl('page', ['record' => $this->getRecord(), 'page' => $record])),
                TextColumn::make('url')
                    ->label('URL')
                    ->limit(60)
                    ->tooltip(fn (Page $record): string => $record->url)
                    ->sortable(),
                TextColumn::make('page_type')
                    ->label('Page type')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
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
                Action::make('setStatus')
                    ->label('Status')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
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
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading('No pages yet')
            ->emptyStateDescription('Add the pages of this website that you optimise, then record optimisation work against them each month.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewProject')
                ->label('Back to project')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('view', ['record' => $this->getRecord()])),
            Action::make('createPage')
                ->label('Add page')
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->modalHeading('Add page')
                ->schema(fn (): array => PageForm::components($this->getProject()))
                ->authorize(fn (): bool => Gate::allows('managePages', $this->getProject()))
                ->action(function (array $data): void {
                    Gate::authorize('managePages', $this->getProject());

                    app(CreatePageAction::class)->handle($this->getProject(), $data);

                    Notification::make()->title('Page added')->success()->send();
                }),
        ];
    }
}
