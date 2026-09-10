<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Analytics\SaveAuthorityMetricsAction;
use App\Actions\Analytics\SaveGa4CountryMetricsAction;
use App\Actions\Analytics\SaveGa4MonthlyMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Filament\Resources\Projects\Concerns\HasProjectWorkspace;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Schemas\AnalyticsForms;
use App\Models\AuthorityMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\GscPageMetric;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Project → Analytics: the selected month's Google Search Console, Google
 * Analytics 4 and site-authority figures, entered manually for now.
 *
 * The project is resolved through ProjectResource::getEloquentQuery() (404
 * for unrelated projects), the page requires the project `view` ability
 * (403), the cycle is always resolved from the project's own cycles, and
 * every save is authorized by MonthlyCyclePolicy::manageAnalytics and
 * re-checked inside the analytics actions. Not a global sidebar module.
 * Presentation reads the existing records only.
 */
class ProjectAnalytics extends ResourcePage
{
    use HasProjectWorkspace;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected string $view = 'filament.resources.projects.pages.project-analytics';

    /**
     * The selected monthly cycle id ('' when the project has no cycles).
     */
    public string $selectedCycle = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $requested = (int) request()->query('cycle');

        if ($requested > 0 && $this->getProject()->monthlyCycles()->whereKey($requested)->exists()) {
            $this->selectedCycle = (string) $requested;
        } else {
            $this->selectedCycle = (string) ($this->defaultCycle()?->getKey() ?? '');
        }
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

    /**
     * Always one of the project's own cycles; a crafted id resolves to null.
     */
    public function getSelectedCycle(): ?MonthlyCycle
    {
        if ((int) $this->selectedCycle <= 0) {
            return null;
        }

        return $this->getProject()->monthlyCycles()->find((int) $this->selectedCycle);
    }

    protected function requireSelectedCycle(): MonthlyCycle
    {
        return $this->getSelectedCycle()
            ?? throw new InvalidArgumentException('Select a reporting month first.');
    }

    protected function defaultCycle(): ?MonthlyCycle
    {
        $cycles = $this->getCycles();
        $current = CyclePeriod::current();

        return $cycles->first(fn (MonthlyCycle $cycle): bool => $cycle->period()->equals($current))
            ?? $cycles->first();
    }

    public function getGscSummary(): ?GscMonthlyMetric
    {
        return $this->getSelectedCycle()?->gscMonthlyMetric()->with('enteredBy')->first();
    }

    public function getGscQueries(): Collection
    {
        return $this->getSelectedCycle()?->gscQueryMetrics()->orderByDesc('clicks')->orderBy('query')->get() ?? new Collection;
    }

    public function getGscPages(): Collection
    {
        return $this->getSelectedCycle()?->gscPageMetrics()->with('page')->orderByDesc('clicks')->orderBy('page_url')->get() ?? new Collection;
    }

    public function getGa4Summary(): ?Ga4MonthlyMetric
    {
        return $this->getSelectedCycle()?->ga4MonthlyMetric()->with('enteredBy')->first();
    }

    public function getGa4Countries(): Collection
    {
        return $this->getSelectedCycle()?->ga4CountryMetrics()->orderByDesc('active_users')->orderBy('country')->get() ?? new Collection;
    }

    public function getAuthority(): ?AuthorityMetric
    {
        return $this->getSelectedCycle()?->authorityMetric()->with('enteredBy')->first();
    }

    public function canManageSelectedCycle(): bool
    {
        $cycle = $this->getSelectedCycle();

        return $cycle !== null && Gate::allows('manageAnalytics', $cycle);
    }

    /**
     * Display form of a landing page URL: "/path" on the project's own site,
     * "host/path" elsewhere. Presentation only; the full URL stays available.
     */
    public function landingPagePath(GscPageMetric $metric): string
    {
        $path = parse_url($metric->page_url, PHP_URL_PATH) ?: '/';
        $host = strtolower((string) parse_url($metric->page_url, PHP_URL_HOST));
        $projectHost = strtolower((string) parse_url((string) $this->getProject()->website_url, PHP_URL_HOST));

        $display = $host !== '' && preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $projectHost) ? $host.$path : $path;

        return mb_strlen($display) > 60 ? mb_substr($display, 0, 57).'…' : $display;
    }

    /**
     * "1m 34s" from stored seconds (presentation only).
     */
    public function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('Import CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->url(fn (): string => ImportBatchResource::getUrl('create', ['project' => $this->getRecord()->getKey()])),
        ];
    }

    protected function editAction(string $name, string $label, string $heading): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->size('sm')
            ->modalHeading(fn (): string => $heading.' — '.($this->getSelectedCycle()?->periodLabel() ?? ''))
            ->modalSubmitActionLabel('Save')
            ->authorize(fn (): bool => $this->canManageSelectedCycle());
    }

    public function editGscSummaryAction(): Action
    {
        return $this->editAction('editGscSummary', 'Edit summary', 'Search Console summary')
            ->modalWidth('lg')
            ->schema(AnalyticsForms::gscSummaryComponents())
            ->fillForm(fn (): array => AnalyticsForms::fillFromGscSummary($this->getGscSummary()))
            ->action(function (array $data, Action $action): void {
                $this->save($action, fn (MonthlyCycle $cycle) => app(SaveGscMonthlyMetricsAction::class)->handle($cycle, $data, $this->currentUser()), 'Search Console summary saved');
            });
    }

    public function editGscQueriesAction(): Action
    {
        return $this->editAction('editGscQueries', 'Edit queries', 'Top queries')
            ->modalWidth('5xl')
            ->schema(AnalyticsForms::gscQueryComponents())
            ->fillForm(fn (): array => AnalyticsForms::fillFromGscQueries($this->requireSelectedCycle()))
            ->action(function (array $data, Action $action): void {
                $this->save($action, fn (MonthlyCycle $cycle) => app(SaveGscQueryMetricsAction::class)->handle($cycle, $data['rows'] ?? [], $this->currentUser()), 'Top queries saved');
            });
    }

    public function editGscPagesAction(): Action
    {
        return $this->editAction('editGscPages', 'Edit landing pages', 'Landing pages')
            ->modalWidth('6xl')
            ->schema(fn (): array => AnalyticsForms::gscPageComponents($this->getProject()))
            ->fillForm(fn (): array => AnalyticsForms::fillFromGscPages($this->requireSelectedCycle()))
            ->action(function (array $data, Action $action): void {
                $this->save($action, fn (MonthlyCycle $cycle) => app(SaveGscPageMetricsAction::class)->handle($cycle, $data['rows'] ?? [], $this->currentUser()), 'Landing pages saved');
            });
    }

    public function editGa4SummaryAction(): Action
    {
        return $this->editAction('editGa4Summary', 'Edit summary', 'Google Analytics summary')
            ->modalWidth('2xl')
            ->schema(AnalyticsForms::ga4SummaryComponents())
            ->fillForm(fn (): array => AnalyticsForms::fillFromGa4Summary($this->getGa4Summary()))
            ->action(function (array $data, Action $action): void {
                $this->save($action, fn (MonthlyCycle $cycle) => app(SaveGa4MonthlyMetricsAction::class)->handle($cycle, $data, $this->currentUser()), 'Google Analytics summary saved');
            });
    }

    public function editGa4CountriesAction(): Action
    {
        return $this->editAction('editGa4Countries', 'Edit countries', 'Audience by country')
            ->modalWidth('6xl')
            ->schema(AnalyticsForms::ga4CountryComponents())
            ->fillForm(fn (): array => AnalyticsForms::fillFromGa4Countries($this->requireSelectedCycle()))
            ->action(function (array $data, Action $action): void {
                $this->save($action, fn (MonthlyCycle $cycle) => app(SaveGa4CountryMetricsAction::class)->handle($cycle, $data['rows'] ?? [], $this->currentUser()), 'Audience by country saved');
            });
    }

    public function editAuthorityAction(): Action
    {
        return $this->editAction('editAuthority', 'Edit metrics', 'Site authority')
            ->modalWidth('2xl')
            ->schema(AnalyticsForms::authorityComponents())
            ->fillForm(fn (): array => AnalyticsForms::fillFromAuthority($this->getAuthority()))
            ->action(function (array $data, Action $action): void {
                $this->save($action, fn (MonthlyCycle $cycle) => app(SaveAuthorityMetricsAction::class)->handle($cycle, $data, $this->currentUser()), 'Site authority saved');
            });
    }

    /**
     * Authorize against the selected cycle (project access + lock), run the
     * domain action, and surface domain errors as notifications.
     */
    protected function save(Action $action, callable $call, string $successTitle): void
    {
        try {
            $cycle = $this->requireSelectedCycle();

            Gate::authorize('manageAnalytics', $cycle);

            $call($cycle);
        } catch (InvalidArgumentException|LockedMonthlyCycleException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();
        }

        Notification::make()->title($successTitle)->success()->send();
    }
}
