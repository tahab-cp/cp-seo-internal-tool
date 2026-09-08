<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Project;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Project')
                    ->columns(2)
                    ->components([
                        TextEntry::make('name')
                            ->label('Project name'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('client.name')
                            ->label('Client')
                            ->url(fn (Project $record): ?string => Filament::auth()->user()?->can('view', $record->client)
                                ? ClientResource::getUrl('view', ['record' => $record->client])
                                : null),
                        TextEntry::make('website_url')
                            ->label('Website')
                            ->url(fn (Project $record): string => $record->website_url)
                            ->openUrlInNewTab(),
                        TextEntry::make('target_location')
                            ->label('Target location')
                            ->placeholder('—'),
                        TextEntry::make('start_date')
                            ->label('Start date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('end_date')
                            ->label('End date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('deleted_at')
                            ->label('Archived')
                            ->dateTime()
                            ->visible(fn (Project $record): bool => $record->trashed()),
                    ]),
                Section::make('Team')
                    ->columns(2)
                    ->components([
                        TextEntry::make('primarySeoUser.name')
                            ->label('Primary SEO owner')
                            ->placeholder('Unassigned'),
                        TextEntry::make('teamMembers.name')
                            ->label('Additional team members')
                            ->badge()
                            ->listWithLineBreaks()
                            ->placeholder('No additional team members'),
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
