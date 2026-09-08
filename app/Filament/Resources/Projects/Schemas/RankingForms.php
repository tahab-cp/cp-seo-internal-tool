<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\MonthlyCycleStatus;
use App\Enums\RankingSource;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Services\Rankings\RankingMovementService;
use App\Support\Rankings\RankingMovement;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\Rule;

/**
 * Single and bulk ranking entry fields. The ranking actions re-validate
 * every rule (same-project keyword and cycle, lock, position, source).
 */
class RankingForms
{
    /**
     * Shared context fields: reporting month, moment and source.
     *
     * @return array<int, Component>
     */
    public static function contextComponents(Project $project, ?int $defaultCycleId = null): array
    {
        $cycles = $project->monthlyCycles()
            ->where('status', '!=', MonthlyCycleStatus::Locked->value)
            ->latestPeriodFirst()
            ->get();

        return [
            Select::make('monthly_cycle_id')
                ->label('Reporting month')
                ->options($cycles->mapWithKeys(fn (MonthlyCycle $cycle): array => [$cycle->id => $cycle->periodLabel()])->all())
                ->default($defaultCycleId)
                ->required()
                ->native(false)
                ->rule(Rule::exists('monthly_cycles', 'id')
                    ->where('project_id', $project->getKey())
                    ->where('status', '!=', MonthlyCycleStatus::Locked->value))
                ->helperText('Locked months cannot be selected.'),
            DateTimePicker::make('checked_at')
                ->label('Checked at')
                ->required()
                ->default(now())
                ->seconds(false)
                ->native(false),
            Select::make('source')
                ->options(RankingSource::class)
                ->default(RankingSource::Manual->value)
                ->required()
                ->native(false),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function positionComponents(): array
    {
        return [
            TextInput::make('position')
                ->label('Position')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->nullable()
                ->placeholder('Not ranking')
                ->helperText('Leave empty for “Not ranking”. 0 is not a valid position.'),
            TextInput::make('ranking_url')
                ->label('Ranking URL')
                ->url()
                ->maxLength(500)
                ->nullable()
                ->placeholder('https://…'),
        ];
    }

    /**
     * Single observation form (record or correct).
     *
     * @return array<int, Component>
     */
    public static function singleComponents(Project $project, ?int $defaultCycleId = null): array
    {
        return [
            ...self::contextComponents($project, $defaultCycleId),
            ...self::positionComponents(),
        ];
    }

    /**
     * Bulk "Update rankings": shared context plus one fixed row per active keyword.
     *
     * @return array<int, Component>
     */
    public static function bulkComponents(Project $project, ?int $defaultCycleId = null): array
    {
        return [
            ...self::contextComponents($project, $defaultCycleId),
            Repeater::make('rows')
                ->label('Active keywords')
                ->columns(4)
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->itemLabel(fn (array $state): ?string => $state['keyword'] ?? null)
                ->schema([
                    Hidden::make('keyword_id'),
                    TextInput::make('keyword')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('previous')
                        ->label('Previous')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Derived from the latest earlier observation.'),
                    TextInput::make('position')
                        ->label('New position')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->nullable()
                        ->placeholder('Not ranking'),
                    TextInput::make('ranking_url')
                        ->label('Ranking URL')
                        ->url()
                        ->maxLength(500)
                        ->nullable(),
                ]),
        ];
    }

    /**
     * Rows for the bulk form: every active keyword with its derived previous position.
     *
     * @return list<array{keyword_id: int, keyword: string, previous: string, position: null, ranking_url: null}>
     */
    public static function bulkRows(Project $project, ?CarbonImmutable $before = null): array
    {
        $movement = app(RankingMovementService::class);
        $before ??= CarbonImmutable::now();

        return $project->keywords()
            ->active()
            ->orderBy('keyword')
            ->get()
            ->map(function (Keyword $keyword) use ($movement, $before): array {
                $previous = $movement->latestSnapshotBefore($keyword, $before);

                return [
                    'keyword_id' => $keyword->id,
                    'keyword' => $keyword->keyword.($keyword->location ? " ({$keyword->location})" : ''),
                    'previous' => $previous
                        ? $previous->positionLabel().' · '.$previous->checked_at->format('j M')
                        : '—',
                    'position' => null,
                    'ranking_url' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromSnapshot(RankingSnapshot $snapshot): array
    {
        return [
            'monthly_cycle_id' => $snapshot->monthly_cycle_id,
            'checked_at' => $snapshot->checked_at?->toDateTimeString(),
            'source' => $snapshot->source->value,
            'position' => $snapshot->position,
            'ranking_url' => $snapshot->ranking_url,
        ];
    }

    public static function positionLabel(?int $position): string
    {
        return RankingMovement::positionLabel($position);
    }
}
