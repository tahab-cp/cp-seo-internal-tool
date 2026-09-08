<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\MonthlyNoteType;
use App\Models\MonthlyNote;
use App\Services\Notes\MonthlyNoteGuard;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * Add / edit note fields. The note actions re-validate every rule.
 */
class MonthlyNoteForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(?MonthlyNoteType $defaultType = null): array
    {
        return [
            Select::make('type')
                ->options(MonthlyNoteType::class)
                ->default($defaultType?->value ?? MonthlyNoteType::Win->value)
                ->required()
                ->native(false),
            TextInput::make('title')
                ->maxLength(MonthlyNoteGuard::TITLE_MAX)
                ->nullable()
                ->placeholder('Optional headline'),
            Textarea::make('body')
                ->required()
                ->rows(5)
                ->maxLength(MonthlyNoteGuard::BODY_MAX)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fillFromNote(MonthlyNote $note): array
    {
        return [
            'type' => $note->type->value,
            'title' => $note->title,
            'body' => $note->body,
        ];
    }
}
