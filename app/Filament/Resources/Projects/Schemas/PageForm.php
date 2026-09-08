<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Actions\Pages\PageAttributes;
use App\Enums\PageStatus;
use App\Models\Page;
use App\Models\Project;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\Rule;

/**
 * Page master-data fields. The page actions re-validate every rule.
 */
class PageForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(Project $project, ?Page $page = null): array
    {
        return [
            TextInput::make('url')
                ->label('URL')
                ->url()
                ->required()
                ->maxLength(PageAttributes::URL_MAX)
                ->placeholder('https://example.com/services/seo')
                ->rule(Rule::unique('pages', 'url')
                    ->where('project_id', $project->getKey())
                    ->ignore($page?->getKey()))
                ->validationMessages(['unique' => 'This URL already exists in this project.'])
                ->columnSpanFull(),
            Select::make('status')
                ->options(PageStatus::class)
                ->required()
                ->default(PageStatus::Active->value)
                ->native(false),
            TextInput::make('page_type')
                ->label('Page type')
                ->maxLength(100)
                ->placeholder('service'),
            TextInput::make('title')
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('path')
                ->maxLength(PageAttributes::URL_MAX)
                ->placeholder('/services/seo')
                ->helperText('Derived from the URL when left empty.')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromPage(Page $page): array
    {
        return [
            'url' => $page->url,
            'status' => $page->status->value,
            'page_type' => $page->page_type,
            'title' => $page->title,
            'path' => $page->path,
        ];
    }
}
