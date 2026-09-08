<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\Permission;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\Actions\ArchiveAction;
use App\Models\Project;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client', 'primarySeoUser', 'package']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Project')
                    ->sortable()
                    // One search box covering project name, client name and website.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('website_url', 'like', "%{$search}%")
                            ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$search}%")),
                    )),
                TextColumn::make('client.name')
                    ->label('Client')
                    ->sortable(),
                TextColumn::make('website_url')
                    ->label('Website')
                    ->url(fn (Project $record): string => $record->website_url)
                    ->openUrlInNewTab()
                    ->limit(40),
                TextColumn::make('package.name')
                    ->label('Package')
                    ->sortable()
                    ->placeholder('No package')
                    ->description(fn (Project $record): ?string => $record->package && ! $record->package->is_active
                        ? 'Inactive package'
                        : null),
                TextColumn::make('primarySeoUser.name')
                    ->label('Primary SEO')
                    ->sortable()
                    ->placeholder('Unassigned'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('start_date')
                    ->label('Start date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ProjectStatus::class),
                SelectFilter::make('client_id')
                    ->label('Client')
                    ->relationship('client', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('package_id')
                    ->label('Package')
                    ->relationship('package', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('primary_seo_user_id')
                    ->label('Primary SEO')
                    ->relationship('primarySeoUser', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make()
                    ->label('Archived')
                    ->visible(fn (): bool => Filament::auth()->user()?->hasPermission(Permission::ArchiveProjects) === true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->hidden(fn (Project $record): bool => $record->trashed()),
                ArchiveAction::make(),
                RestoreAction::make()
                    ->successNotificationTitle('Project restored'),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedRocketLaunch)
            ->emptyStateHeading('No projects yet')
            ->emptyStateDescription(
                'A project is one SEO engagement or website for a client. Monthly targets, tasks, '
                .'keywords, backlinks, content, analytics and reports all belong to a project.'
            )
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }
}
