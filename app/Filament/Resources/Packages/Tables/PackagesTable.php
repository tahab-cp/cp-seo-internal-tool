<?php

namespace App\Filament\Resources\Packages\Tables;

use App\Filament\Resources\Packages\Actions\PackageStatusActions;
use App\Models\Package;
use App\Models\PackageTarget;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('targets')->withCount(['targets', 'projects']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Package')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Package $record): ?string => $record->description),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('targets_count')
                    ->label('Targets')
                    ->sortable(),
                TextColumn::make('targets_summary')
                    ->label('Monthly targets')
                    ->state(fn (Package $record): string => $record->targets
                        ->map(fn (PackageTarget $target): string => "{$target->label} {$target->target_value}")
                        ->implode(' · '))
                    ->placeholder('No targets')
                    ->wrap(),
                TextColumn::make('projects_count')
                    ->label('Projects')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                PackageStatusActions::deactivate(),
                PackageStatusActions::activate(),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedCube)
            ->emptyStateHeading('No packages yet')
            ->emptyStateDescription('Packages define the default monthly deliverables (backlinks, blogs, guest posts, pages optimised) that projects inherit and may override.')
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }
}
