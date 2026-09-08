<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Pages\RecordPageOptimizationAction;
use App\Actions\Pages\SetPageStatusAction;
use App\Actions\Pages\UpdatePageAction;
use App\Actions\Pages\UpdatePageOptimizationAction;
use App\Enums\PageStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\PageForm;
use App\Filament\Resources\Projects\Schemas\PageOptimizationForm;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Project → Pages → one page: master data plus its optimisation history,
 * with the Record optimisation action.
 *
 * The page is resolved through Page::scopeAccessibleBy() inside the
 * project (404 for anything outside the actor's projects).
 */
class ProjectPageDetail extends ResourcePage implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-page-detail';

    #[Locked]
    public int $pageId;

    public function mount(int|string $record, int|string $page): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->pageId = Page::query()
            ->where('project_id', $this->getRecord()->getKey())
            ->accessibleBy($this->currentUser())
            ->findOrFail((int) $page)
            ->getKey();
    }

    public function getTitle(): string
    {
        return $this->getPage()->displayName();
    }

    public function getSubheading(): ?string
    {
        return $this->getProject()->name.' — '.$this->getPage()->url;
    }

    public function getProject(): Project
    {
        /** @var Project $project */
        $project = $this->getRecord();

        return $project;
    }

    public function getPage(): Page
    {
        return Page::query()
            ->where('project_id', $this->getProject()->getKey())
            ->findOrFail($this->pageId);
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
            ->query(fn (): Builder => PageOptimization::query()
                ->where('page_id', $this->pageId)
                ->accessibleBy($this->currentUser())
                ->with(['monthlyCycle', 'user']))
            ->defaultSort('optimized_at', 'desc')
            ->columns([
                TextColumn::make('optimized_at')
                    ->label('Date')
                    ->dateTime('j M Y H:i')
                    ->sortable(),
                TextColumn::make('monthlyCycle.year')
                    ->label('Reporting month')
                    ->state(fn (PageOptimization $record): string => $record->monthlyCycle?->periodLabel() ?? '—')
                    ->badge()
                    ->color(fn (PageOptimization $record): string => $record->isLocked() ? 'gray' : 'info'),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('—'),
                TextColumn::make('changes')
                    ->label('Changes')
                    ->state(fn (PageOptimization $record): array => $record->changeLabels())
                    ->badge()
                    ->listWithLineBreaks(),
                TextColumn::make('notes')
                    ->limit(60)
                    ->placeholder('—')
                    ->tooltip(fn (PageOptimization $record): ?string => $record->notes),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading('Edit optimisation')
                    ->schema(fn (): array => PageOptimizationForm::components($this->getProject()))
                    ->fillForm(fn (PageOptimization $record): array => PageOptimizationForm::fillFromOptimization($record))
                    ->authorize(fn (PageOptimization $record): bool => Gate::allows('update', $record))
                    ->action(function (PageOptimization $record, array $data): void {
                        Gate::authorize('update', $record);

                        app(UpdatePageOptimizationAction::class)->handle($record, $data);

                        Notification::make()->title('Optimisation updated')->success()->send();
                    }),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver)
            ->emptyStateHeading('No optimisation work recorded yet')
            ->emptyStateDescription('Use “Record optimisation” to log the SEO work done on this page each month.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToPages')
                ->label('All pages')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => ProjectResource::getUrl('pages', ['record' => $this->getRecord()])),
            Action::make('recordOptimization')
                ->label('Record optimisation')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->modalHeading('Record optimisation')
                ->schema(fn (): array => PageOptimizationForm::components($this->getProject(), $this->defaultCycleId()))
                ->visible(fn (): bool => ! $this->getPage()->isRemoved())
                ->authorize(fn (): bool => Gate::allows('managePages', $this->getProject()))
                ->action(function (array $data): void {
                    Gate::authorize('managePages', $this->getProject());

                    app(RecordPageOptimizationAction::class)->handle(
                        $this->getProject(),
                        $data + ['page_id' => $this->pageId],
                        $this->currentUser(),
                    );

                    Notification::make()->title('Optimisation recorded')->success()->send();
                }),
            Action::make('editPage')
                ->label('Edit page')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('gray')
                ->modalHeading('Edit page')
                ->schema(fn (): array => PageForm::components($this->getProject(), $this->getPage()))
                ->fillForm(fn (): array => PageForm::fillFromPage($this->getPage()))
                ->authorize(fn (): bool => Gate::allows('update', $this->getPage()))
                ->action(function (array $data): void {
                    Gate::authorize('update', $this->getPage());

                    app(UpdatePageAction::class)->handle($this->getPage(), $data);

                    Notification::make()->title('Page updated')->success()->send();
                }),
            Action::make('markRemoved')
                ->label('Mark removed')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The page stays in the project as history and keeps all optimisation records.')
                ->visible(fn (): bool => ! $this->getPage()->isRemoved())
                ->authorize(fn (): bool => Gate::allows('setStatus', $this->getPage()))
                ->action(function (): void {
                    Gate::authorize('setStatus', $this->getPage());

                    app(SetPageStatusAction::class)->handle($this->getPage(), PageStatus::Removed);
                }),
            Action::make('reactivate')
                ->label('Reactivate')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getPage()->isRemoved())
                ->authorize(fn (): bool => Gate::allows('setStatus', $this->getPage()))
                ->action(function (): void {
                    Gate::authorize('setStatus', $this->getPage());

                    app(SetPageStatusAction::class)->handle($this->getPage(), PageStatus::Active);
                }),
        ];
    }
}
