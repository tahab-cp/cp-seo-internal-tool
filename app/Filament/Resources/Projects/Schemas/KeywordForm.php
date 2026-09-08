<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\KeywordIntent;
use App\Enums\KeywordRole;
use App\Enums\KeywordStatus;
use App\Models\Keyword;
use App\Models\Page;
use App\Models\Project;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\Rule;

/**
 * Keyword master-data fields. The keyword actions re-validate every rule,
 * including normalised uniqueness and same-project target page.
 */
class KeywordForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(Project $project, ?Keyword $keyword = null): array
    {
        $pages = $project->pages()->orderBy('url')->get();

        return [
            TextInput::make('keyword')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Select::make('status')
                ->options(KeywordStatus::class)
                ->required()
                ->default(KeywordStatus::Active->value)
                ->native(false),
            Select::make('target_page_id')
                ->label('Target page')
                ->options($pages->mapWithKeys(fn (Page $page): array => [$page->id => $page->displayName().' — '.$page->url])->all())
                ->searchable()
                ->nullable()
                ->native(false)
                ->rule(Rule::exists('pages', 'id')->where('project_id', $project->getKey())),
            Select::make('keyword_role')
                ->label('Role')
                ->options(KeywordRole::class)
                ->nullable()
                ->native(false),
            Select::make('search_intent')
                ->label('Intent')
                ->options(KeywordIntent::class)
                ->nullable()
                ->native(false),
            TextInput::make('search_volume')
                ->label('Search volume')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->nullable(),
            TextInput::make('keyword_difficulty')
                ->label('Keyword difficulty (KD)')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->nullable(),
            TextInput::make('location')
                ->maxLength(255)
                ->placeholder('London'),
            Toggle::make('is_branded')
                ->label('Branded keyword')
                ->default(false)
                ->inline(false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromKeyword(Keyword $keyword): array
    {
        return [
            'keyword' => $keyword->keyword,
            'status' => $keyword->status->value,
            'target_page_id' => $keyword->target_page_id,
            'keyword_role' => $keyword->keyword_role?->value,
            'search_intent' => $keyword->search_intent?->value,
            'search_volume' => $keyword->search_volume,
            'keyword_difficulty' => $keyword->keyword_difficulty,
            'location' => $keyword->location,
            'is_branded' => $keyword->is_branded,
        ];
    }
}
