<?php

namespace App\Filament\Pages;

use App\Enums\ReportStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\MonthlyReport;
use App\Models\Project;
use App\Models\User;
use App\Services\Dashboard\ReportsOverviewQuery;
use App\Services\Reports\ReportReadinessService;
use App\Support\MonthlyCycles\CyclePeriod;
use App\Support\Reports\ReportReadiness;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Global reports overview: every monthly report the user may see, with
 * live readiness. Actions only navigate into the existing project report
 * editor; no report logic lives here.
 */
class ReportsOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Reports';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.reports-overview';

    /**
     * @var array<int, ReportReadiness>
     */
    protected array $readinessCache = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $user?->can('viewAny', MonthlyReport::class) === true;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /**
     * Readiness for the rows on the current page, evaluated in ONE batch
     * the first time any row asks (fixed query count per page, not per
     * report). A record outside the page falls back to a single evaluation.
     */
    public function readinessFor(MonthlyReport $report): ReportReadiness
    {
        if (! isset($this->readinessCache[$report->getKey()])) {
            $records = $this->getTableRecords();
            $page = $records instanceof EloquentCollection ? $records : $records->getCollection();

            foreach (app(ReportReadinessService::class)->evaluateMany($page) as $id => $readiness) {
                $this->readinessCache[$id] = $readiness;
            }
        }

        return $this->readinessCache[$report->getKey()] ??= app(ReportReadinessService::class)->evaluate($report);
    }

    public function table(Table $table): Table
    {
        $overview = app(ReportsOverviewQuery::class);

        return $table
            ->query(fn (): Builder => $overview->for($this->currentUser()))
            ->columns([
                TextColumn::make('monthlyCycle.project.client.name')->label('Client'),
                TextColumn::make('monthlyCycle.project.name')->label('Project')
                    ->url(fn (MonthlyReport $record): string => ProjectResource::getUrl('view', ['record' => $record->monthlyCycle->project])),
                TextColumn::make('period')->label('Period')
                    ->state(fn (MonthlyReport $record): string => $record->monthlyCycle->periodLabel()),
                TextColumn::make('version')->label('Version')
                    ->state(fn (MonthlyReport $record): string => $record->versionLabel())
                    ->description(fn (MonthlyReport $record): ?string => $record->revisions_count > 0 ? $record->revisions_count.' superseded' : null),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (ReportStatus $state, MonthlyReport $record): string => $state->getLabel().($record->isCorrection() ? ' · correction' : ''))
                    ->color(fn (MonthlyReport $record): string => $record->status->getColor()),
                TextColumn::make('readiness')->label('Readiness')
                    ->state(fn (MonthlyReport $record): string => $this->readinessFor($record)->percentage().'%')
                    ->badge()
                    ->color(fn (MonthlyReport $record): string => $this->readinessFor($record)->isReady() ? 'success' : 'warning'),
                TextColumn::make('required_sections')->label('Required sections')
                    ->state(fn (MonthlyReport $record): string => ($r = $this->readinessFor($record))->completedRequiredCount().' / '.$r->requiredCount()),
                TextColumn::make('finalized_at')->label('Finalized')->dateTime('j M Y H:i')->placeholder('—'),
                TextColumn::make('finalizedBy.name')->label('Finalized by')->placeholder('—'),
                TextColumn::make('monthlyCycle.project.primarySeoUser.name')->label('Primary SEO')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('period')->label('Reporting period')
                    ->options(fn (): array => MonthlyCycle::query()
                        ->whereIn('project_id', Project::query()->accessibleBy($this->currentUser())->select('id'))
                        ->selectRaw('DISTINCT year, month')->orderByDesc('year')->orderByDesc('month')->get()
                        ->mapWithKeys(fn ($c): array => [sprintf('%04d-%02d', $c->year, $c->month) => (new CyclePeriod((int) $c->year, (int) $c->month))->label()])->all())
                    ->query(function (Builder $query, array $data) use ($overview): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        [$year, $month] = array_map('intval', explode('-', $data['value']));

                        return $overview->period($query, new CyclePeriod($year, $month));
                    }),
                SelectFilter::make('status')->label('Status')
                    ->options(ReportStatus::class)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('monthly_reports.status', $data['value']) : $query),
                SelectFilter::make('client')->label('Client')
                    ->options(fn (): array => Client::query()->whereIn('id', Project::query()->accessibleBy($this->currentUser())->select('client_id'))->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('projects.client_id', $data['value']) : $query),
                SelectFilter::make('project')->label('Project')
                    ->options(fn (): array => Project::query()->accessibleBy($this->currentUser())->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('projects.id', $data['value']) : $query),
                SelectFilter::make('primary_seo')->label('Primary SEO')
                    ->options(fn (): array => User::query()->whereIn('id', Project::query()->accessibleBy($this->currentUser())->select('primary_seo_user_id'))->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('projects.primary_seo_user_id', $data['value']) : $query),
                Filter::make('ready')->label('Ready for review')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('monthly_reports.status', ReportStatus::ReadyForReview->value)),
                Filter::make('incomplete')->label('Incomplete (draft)')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('monthly_reports.status', ReportStatus::Draft->value)),
                Filter::make('final')->label('Final')->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('monthly_reports.status', ReportStatus::Final->value)),
                Filter::make('correction')->label('Correction in progress')->toggle()
                    ->query(fn (Builder $query): Builder => $overview->corrections($query)),
            ])
            ->searchable()
            ->searchUsing(fn (Builder $query, string $search): Builder => $overview->search($query, $search))
            ->recordActions([
                Action::make('open')
                    ->label(fn (MonthlyReport $record): string => $record->isFinal() ? 'View' : 'Open')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (MonthlyReport $record): string => ProjectResource::getUrl('report', ['record' => $record->monthlyCycle->project, 'report' => $record])),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No reports yet')
            ->emptyStateDescription('Reports appear once a draft has been started for a reporting month.');
    }
}
