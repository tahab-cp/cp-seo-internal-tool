<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Project;
use App\Services\MonthlyCycles\TargetResolver;
use App\Support\Targets\ResolvedTarget;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
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
                Section::make('Package & monthly targets')
                    ->description('Package defaults with this project\'s overrides. Changes here affect future monthly cycles only.')
                    ->components([
                        TextEntry::make('package.name')
                            ->label('Package')
                            ->badge()
                            ->color(fn (Project $record): string => $record->package?->is_active ? 'success' : 'warning')
                            ->formatStateUsing(fn (string $state, Project $record): string => $record->package?->is_active
                                ? $state
                                : "{$state} (inactive)")
                            ->placeholder('No package assigned'),
                        RepeatableEntry::make('resolved_targets')
                            ->hiddenLabel()
                            ->state(fn (Project $record): array => app(TargetResolver::class)
                                ->resolve($record)
                                ->map(fn (ResolvedTarget $target): array => $target->toArray())
                                ->all())
                            ->visible(fn (Project $record): bool => $record->package_id !== null)
                            ->columns(4)
                            ->schema([
                                TextEntry::make('label')
                                    ->label('Target'),
                                TextEntry::make('package_value')
                                    ->label('Package default'),
                                TextEntry::make('override_value')
                                    ->label('Project override')
                                    ->placeholder('—'),
                                TextEntry::make('resolved_value')
                                    ->label('Resolved monthly target')
                                    ->weight('bold'),
                            ]),
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
