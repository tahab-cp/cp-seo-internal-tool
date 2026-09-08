<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\AuthorityMetric;
use App\Models\Ga4CountryMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\GscPageMetric;
use App\Models\GscQueryMetric;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Validation\Rule;

/**
 * Manual-entry forms for the month's analytics. Percentages are entered as
 * human percentages (8.5 = 8.5%). The analytics actions re-validate every
 * rule server-side (lock, author, same-project page, ranges, identities).
 */
class AnalyticsForms
{
    protected static function count(string $name, string $label, bool $required = false): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->integer()
            ->minValue(0)
            ->required($required)
            ->nullable(! $required);
    }

    protected static function percentage(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->suffix('%')
            ->nullable()
            ->helperText('8.5 means 8.5%');
    }

    protected static function position(string $name = 'average_position', string $label = 'Average position'): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->rule('gt:0')
            ->step(0.01)
            ->nullable()
            ->placeholder('Unknown')
            ->helperText('Positive number; leave empty when unknown. 0 is not valid.');
    }

    protected static function score(string $name, string $label, bool $integer): TextInput
    {
        $input = TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->nullable();

        return $integer ? $input->integer() : $input->step(0.1);
    }

    /**
     * @return array<int, Component>
     */
    public static function gscSummaryComponents(): array
    {
        return [
            self::count('clicks', 'Clicks', required: true),
            self::count('impressions', 'Impressions', required: true),
            self::percentage('ctr', 'CTR'),
            self::position(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromGscSummary(?GscMonthlyMetric $metric): array
    {
        return [
            'clicks' => $metric?->clicks,
            'impressions' => $metric?->impressions,
            'ctr' => $metric?->ctr,
            'average_position' => $metric?->average_position,
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function gscQueryComponents(): array
    {
        return [
            Repeater::make('rows')
                ->label('Top queries')
                ->columns(5)
                ->reorderable(false)
                ->addActionLabel('Add query')
                ->itemLabel(fn (array $state): ?string => $state['query'] ?? null)
                ->schema([
                    TextInput::make('query')->required()->maxLength(255)->distinct(),
                    self::count('clicks', 'Clicks', required: true),
                    self::count('impressions', 'Impressions', required: true),
                    self::percentage('ctr', 'CTR'),
                    self::position(),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromGscQueries(MonthlyCycle $cycle): array
    {
        return [
            'rows' => $cycle->gscQueryMetrics()->orderByDesc('clicks')->orderBy('query')->get()
                ->map(fn (GscQueryMetric $m): array => [
                    'query' => $m->query,
                    'clicks' => $m->clicks,
                    'impressions' => $m->impressions,
                    'ctr' => $m->ctr,
                    'average_position' => $m->average_position,
                ])->values()->all(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function gscPageComponents(Project $project): array
    {
        $pages = $project->pages()
            ->orderBy('url')
            ->get()
            ->mapWithKeys(fn (Page $page): array => [$page->id => $page->url])
            ->all();

        return [
            Repeater::make('rows')
                ->label('Landing pages')
                ->columns(6)
                ->reorderable(false)
                ->addActionLabel('Add landing page')
                ->itemLabel(fn (array $state): ?string => $state['page_url'] ?? null)
                ->schema([
                    TextInput::make('page_url')->label('Page URL')->url()->required()->maxLength(500)->distinct()->columnSpan(2),
                    Select::make('page_id')
                        ->label('Mapped page')
                        ->options($pages)
                        ->searchable()
                        ->nullable()
                        ->native(false)
                        ->placeholder('Not mapped')
                        ->rule(Rule::exists('pages', 'id')->where('project_id', $project->getKey())),
                    self::count('clicks', 'Clicks', required: true),
                    self::count('impressions', 'Impressions', required: true),
                    self::percentage('ctr', 'CTR'),
                    self::position(),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromGscPages(MonthlyCycle $cycle): array
    {
        return [
            'rows' => $cycle->gscPageMetrics()->orderByDesc('clicks')->orderBy('page_url')->get()
                ->map(fn (GscPageMetric $m): array => [
                    'page_url' => $m->page_url,
                    'page_id' => $m->page_id,
                    'clicks' => $m->clicks,
                    'impressions' => $m->impressions,
                    'ctr' => $m->ctr,
                    'average_position' => $m->average_position,
                ])->values()->all(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function ga4SummaryComponents(): array
    {
        return [
            self::count('active_users', 'Active users'),
            self::count('new_users', 'New users'),
            self::count('sessions', 'Sessions'),
            self::count('organic_sessions', 'Organic sessions'),
            self::count('engaged_sessions', 'Engaged sessions'),
            self::percentage('engagement_rate', 'Engagement rate'),
            self::count('average_engagement_time_seconds', 'Average engagement time (seconds)'),
            self::count('event_count', 'Event count'),
            self::count('key_events', 'Key events'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromGa4Summary(?Ga4MonthlyMetric $metric): array
    {
        return [
            'active_users' => $metric?->active_users,
            'new_users' => $metric?->new_users,
            'sessions' => $metric?->sessions,
            'organic_sessions' => $metric?->organic_sessions,
            'engaged_sessions' => $metric?->engaged_sessions,
            'engagement_rate' => $metric?->engagement_rate,
            'average_engagement_time_seconds' => $metric?->average_engagement_time_seconds,
            'event_count' => $metric?->event_count,
            'key_events' => $metric?->key_events,
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function ga4CountryComponents(): array
    {
        return [
            Repeater::make('rows')
                ->label('Countries')
                ->columns(4)
                ->reorderable(false)
                ->addActionLabel('Add country')
                ->itemLabel(fn (array $state): ?string => $state['country'] ?? null)
                ->schema([
                    TextInput::make('country')->required()->maxLength(100)->distinct(),
                    self::count('active_users', 'Active users'),
                    self::count('new_users', 'New users'),
                    self::count('sessions', 'Sessions'),
                    self::count('engaged_sessions', 'Engaged sessions'),
                    self::percentage('engagement_rate', 'Engagement rate'),
                    self::count('event_count', 'Event count'),
                    self::count('key_events', 'Key events'),
                ])
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromGa4Countries(MonthlyCycle $cycle): array
    {
        return [
            'rows' => $cycle->ga4CountryMetrics()->orderByDesc('active_users')->orderBy('country')->get()
                ->map(fn (Ga4CountryMetric $m): array => [
                    'country' => $m->country,
                    'active_users' => $m->active_users,
                    'new_users' => $m->new_users,
                    'sessions' => $m->sessions,
                    'engaged_sessions' => $m->engaged_sessions,
                    'engagement_rate' => $m->engagement_rate,
                    'event_count' => $m->event_count,
                    'key_events' => $m->key_events,
                ])->values()->all(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function authorityComponents(): array
    {
        return [
            Section::make('Moz')
                ->columns(2)
                ->schema([
                    self::score('moz_domain_authority', 'Domain Authority', integer: true),
                    self::count('moz_linking_root_domains', 'Linking root domains'),
                ]),
            Section::make('Ahrefs')
                ->columns(2)
                ->schema([
                    self::score('ahrefs_domain_rating', 'Domain Rating', integer: false),
                    self::score('ahrefs_url_rating', 'URL Rating', integer: false),
                ]),
            Section::make('General')
                ->description('Site-wide totals reported by the external tool. Not the monthly link-building deliverables.')
                ->columns(2)
                ->schema([
                    self::count('backlinks_count', 'Backlinks'),
                    self::count('referring_domains_count', 'Referring domains'),
                    Textarea::make('notes')->rows(3)->maxLength(5000)->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromAuthority(?AuthorityMetric $metric): array
    {
        return [
            'moz_domain_authority' => $metric?->moz_domain_authority,
            'moz_linking_root_domains' => $metric?->moz_linking_root_domains,
            'ahrefs_domain_rating' => $metric?->ahrefs_domain_rating,
            'ahrefs_url_rating' => $metric?->ahrefs_url_rating,
            'backlinks_count' => $metric?->backlinks_count,
            'referring_domains_count' => $metric?->referring_domains_count,
            'notes' => $metric?->notes,
        ];
    }
}
