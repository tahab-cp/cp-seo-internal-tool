<?php

namespace App\Filament\Resources\TaskTemplates\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaskTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Template')
                    ->description('A reusable onboarding checklist copied into project tasks on demand.')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Inactive templates cannot be used for new onboarding generation.'),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
                Section::make('Checklist items')
                    ->description('Items are generated in sort order. Due dates are calculated from the project start date (or the generation date).')
                    ->components([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->columns(3)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Add item')
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('phase')
                                    ->maxLength(100)
                                    ->placeholder('Setup'),
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                Textarea::make('description')
                                    ->rows(2)
                                    ->maxLength(2000)
                                    ->columnSpanFull(),
                                TextInput::make('category')
                                    ->maxLength(100)
                                    ->placeholder('Technical'),
                                TextInput::make('default_due_days')
                                    ->label('Default due (days)')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(3650)
                                    ->nullable(),
                                TextInput::make('sort_order')
                                    ->label('Sort order')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->default(0),
                            ]),
                    ]),
            ]);
    }
}
