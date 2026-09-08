<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Enums\MonthlyCycleStatus;
use App\Models\Backlink;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Services\Backlinks\BacklinkGuard;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\Rule;

/**
 * Add / edit backlink fields. The backlink actions re-validate every rule
 * (same-project cycle, lock, URLs, enums, metric ranges).
 */
class BacklinkForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(Project $project, ?int $defaultCycleId = null): array
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
            TextInput::make('published_url')
                ->label('Published URL')
                ->url()
                ->required()
                ->maxLength(BacklinkGuard::URL_MAX)
                ->placeholder('https://example.com/article'),
            TextInput::make('anchor_text')
                ->label('Anchor text')
                ->maxLength(255),
            TextInput::make('target_url')
                ->label('Target URL')
                ->url()
                ->maxLength(BacklinkGuard::URL_MAX)
                ->nullable()
                ->placeholder('https://client.example/page'),
            Select::make('type')
                ->options(BacklinkType::class)
                ->required()
                ->native(false),
            Select::make('status')
                ->options(BacklinkStatus::class)
                ->required()
                ->default(BacklinkStatus::Planned->value)
                ->native(false),
            DatePicker::make('published_date')
                ->label('Published date')
                ->native(false),
            TextInput::make('domain_authority')
                ->label('Domain authority (DA)')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(100)
                ->nullable(),
            TextInput::make('domain_rating')
                ->label('Domain rating (DR)')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(100)
                ->nullable(),
            TextInput::make('spam_score')
                ->label('Spam score')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(100)
                ->nullable(),
            Textarea::make('notes')
                ->rows(3)
                ->maxLength(5000)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromBacklink(Backlink $backlink): array
    {
        return [
            'monthly_cycle_id' => $backlink->monthly_cycle_id,
            'published_url' => $backlink->published_url,
            'anchor_text' => $backlink->anchor_text,
            'target_url' => $backlink->target_url,
            'type' => $backlink->type->value,
            'status' => $backlink->status->value,
            'published_date' => $backlink->published_date?->toDateString(),
            'domain_authority' => $backlink->domain_authority,
            'domain_rating' => $backlink->domain_rating,
            'spam_score' => $backlink->spam_score,
            'notes' => $backlink->notes,
        ];
    }
}
