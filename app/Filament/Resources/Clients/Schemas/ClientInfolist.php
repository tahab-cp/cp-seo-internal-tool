<?php

namespace App\Filament\Resources\Clients\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client')
                    ->columns(2)
                    ->components([
                        TextEntry::make('name')
                            ->label('Client name'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('company_name')
                            ->label('Company')
                            ->placeholder('—'),
                        TextEntry::make('contact_name')
                            ->label('Primary contact')
                            ->placeholder('—'),
                        TextEntry::make('email')
                            ->label('Email address')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('phone')
                            ->placeholder('—'),
                        TextEntry::make('accountManager.name')
                            ->label('Account manager')
                            ->placeholder('Unassigned'),
                        TextEntry::make('updated_at')
                            ->label('Last updated')
                            ->dateTime(),
                    ]),
                Section::make('Internal notes')
                    ->components([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->placeholder('No internal notes.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
