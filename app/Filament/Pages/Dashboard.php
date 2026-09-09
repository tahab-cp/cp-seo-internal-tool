<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\DashboardOverviewService;
use App\Services\Dashboard\ProjectOperationsService;
use App\Support\Dashboard\DashboardOverview;
use App\Support\Dashboard\ProjectOperationsRow;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The operations home screen: what needs attention this period, the
 * signed-in user's own work, a reviewer queue, and an operations table of
 * active projects. Every figure comes from the dashboard services and is
 * scoped to the projects the user may see; rendering never creates or
 * changes data.
 */
class Dashboard extends BaseDashboard implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Operations';

    protected static ?string $navigationLabel = 'Dashboard';

    protected string $view = 'filament.pages.dashboard';

    protected ?DashboardOverview $overview = null;

    /**
     * @var Collection<int, ProjectOperationsRow>|null
     */
    protected ?Collection $tableRows = null;

    public static function canAccess(): bool
    {
        return Filament::auth()->check();
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    public function getPeriod(): CyclePeriod
    {
        return CyclePeriod::current();
    }

    public function getOverview(): DashboardOverview
    {
        return $this->overview ??= app(DashboardOverviewService::class)->for($this->currentUser(), $this->getPeriod());
    }

    public function canReview(): bool
    {
        return $this->currentUser()->can('viewAny', MonthlyReport::class)
            && $this->currentUser()->hasPermission(Permission::FinalizeReports);
    }

    public function canSeeTeamWorkload(): bool
    {
        return TeamWorkload::canAccess();
    }

    /**
     * Ready-for-review reports for the period, with the moment they were
     * marked ready (from the audit trail) when available.
     *
     * @return Collection<int, ProjectOperationsRow>
     */
    public function getReviewQueue(): Collection
    {
        return $this->getOverview()->rows
            ->filter(fn (ProjectOperationsRow $row): bool => $row->report?->isReadyForReview() === true)
            ->values();
    }

    public function reportUrl(ProjectOperationsRow $row): ?string
    {
        return $row->report ? ProjectResource::getUrl('report', ['record' => $row->project, 'report' => $row->report]) : null;
    }

    /**
     * Operations rows for the table, computed once per request for the
     * filtered set of projects (grouped queries, no per-row lookups).
     *
     * @return Collection<int, ProjectOperationsRow>
     */
    protected function tableRows(EloquentCollection $projects): Collection
    {
        return $this->tableRows ??= app(ProjectOperationsService::class)->rowsForProjects($projects, $this->getPeriod());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Project::query()
                ->accessibleBy($this->currentUser())
                ->with(['client', 'primarySeoUser'])
                ->orderBy('name'))
            ->defaultSort('name')
            ->paginated([10, 25, 50])
            ->heading('Active projects — '.$this->getPeriod()->label())
            ->description('Operational status per project for the current reporting period. Numbers are indicators; open a project for the detail.')
            ->columns([
                TextColumn::make('client.name')->label('Client')->searchable()->sortable(),
                TextColumn::make('name')->label('Project')->searchable()->sortable()
                    ->url(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record])),
                TextColumn::make('primarySeoUser.name')->label('Primary SEO')->placeholder('Unassigned'),
                TextColumn::make('cycle_status')->label('Cycle')->badge()
                    ->state(fn (Project $record): string => $this->rowFor($record)?->cycle?->status->getLabel() ?? 'Missing')
                    ->color(fn (Project $record): string => $this->rowFor($record)?->cycle ? $this->rowFor($record)->cycle->status->getColor() : 'danger'),
                TextColumn::make('completion')->label('Target completion')
                    ->state(fn (Project $record): string => $this->rowFor($record)?->completionLabel() ?? '—'),
                TextColumn::make('open_tasks')->label('Open tasks')
                    ->state(fn (Project $record): int => $this->rowFor($record)?->openTasks ?? 0),
                TextColumn::make('overdue_tasks')->label('Overdue')
                    ->state(fn (Project $record): int => $this->rowFor($record)?->overdueTasks ?? 0)
                    ->color(fn (Project $record): ?string => ($this->rowFor($record)?->overdueTasks ?? 0) > 0 ? 'danger' : null),
                TextColumn::make('report_status')->label('Report')->badge()
                    ->state(fn (Project $record): string => $this->rowFor($record)?->reportStatusLabel() ?? '—')
                    ->color(fn (Project $record): string => $this->rowFor($record)?->report?->status->getColor() ?? 'gray'),
                TextColumn::make('readiness')->label('Readiness')
                    ->state(fn (Project $record): string => $this->rowFor($record)?->readinessLabel() ?? '—'),
            ])
            ->filters([
                SelectFilter::make('client_id')->label('Client')
                    ->options(fn (): array => Client::query()->whereIn('id', Project::query()->accessibleBy($this->currentUser())->select('client_id'))->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('primary_seo_user_id')->label('Primary SEO')
                    ->options(fn (): array => User::query()->whereIn('id', Project::query()->accessibleBy($this->currentUser())->select('primary_seo_user_id'))->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->label('Project status')
                    ->options(ProjectStatus::class)
                    ->default(ProjectStatus::Active->value),
                SelectFilter::make('report_status')->label('Report status')
                    ->options(['none' => 'Not started'] + collect(ReportStatus::cases())->mapWithKeys(fn (ReportStatus $s): array => [$s->value => $s->getLabel()])->all())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereDoesntHave('monthlyCycles', fn (Builder $c) => $c->forPeriod($this->getPeriod())->has('monthlyReport')),
                        default => $query->whereHas('monthlyCycles', fn (Builder $c) => $c->forPeriod($this->getPeriod())->whereHas('monthlyReport', fn (Builder $r) => $r->where('status', $data['value']))),
                    }),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record])),
                Action::make('report')->label('Report')->icon(Heroicon::OutlinedDocumentChartBar)->color('gray')
                    ->visible(fn (Project $record): bool => $this->rowFor($record)?->report !== null)
                    ->url(fn (Project $record): ?string => ($row = $this->rowFor($record)) ? $this->reportUrl($row) : null),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No projects to show')
            ->emptyStateDescription('Projects you can access appear here with their operational status for the current month.');
    }

    protected function rowFor(Project $record): ?ProjectOperationsRow
    {
        /** @var EloquentCollection<int, Project> $records */
        $records = $this->getTableRecords();

        return $this->tableRows($records instanceof EloquentCollection ? $records : $records->getCollection())->get($record->getKey());
    }
}
