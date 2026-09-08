<?php

namespace App\Filament\Resources\TaskTemplates\Tables;

use App\Filament\Resources\TaskTemplates\Actions\TaskTemplateStatusActions;
use App\Models\TaskTemplate;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('items'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Template')
                    ->searchable()
                    ->sortable()
                    ->description(fn (TaskTemplate $record): ?string => $record->description),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                TaskTemplateStatusActions::deactivate(),
                TaskTemplateStatusActions::activate(),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->emptyStateHeading('No task templates yet')
            ->emptyStateDescription('Templates are reusable onboarding checklists (GSC setup, GA4 setup, technical audit, keyword research…) that can be generated into a project\'s tasks.')
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }
}
