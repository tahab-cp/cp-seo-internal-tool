<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Models\PackageTarget;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Package')
                    ->description('Reusable monthly deliverable defaults. Changes affect future monthly cycles only.')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Inactive packages cannot be assigned to new projects but stay on existing ones.'),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
                Section::make('Monthly targets')
                    ->description('Each target needs a unique key. Projects may override individual values.')
                    ->components([
                        Repeater::make('targets')
                            ->hiddenLabel()
                            ->columns(4)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Add target')
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->schema([
                                TextInput::make('label')
                                    ->required()
                                    ->maxLength(100),
                                TextInput::make('target_key')
                                    ->label('Target key')
                                    ->required()
                                    ->maxLength(64)
                                    ->regex(PackageTarget::KEY_PATTERN)
                                    ->distinct()
                                    ->placeholder('guest_posts')
                                    ->helperText('Lowercase letters, numbers and underscores.'),
                                TextInput::make('target_value')
                                    ->label('Monthly target')
                                    ->required()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(1_000_000),
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
