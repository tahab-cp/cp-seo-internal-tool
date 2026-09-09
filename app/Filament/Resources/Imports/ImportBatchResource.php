<?php

namespace App\Filament\Resources\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Filament\Resources\Imports\Pages\ListImportBatches;
use App\Filament\Resources\Imports\Pages\NewImport;
use App\Filament\Resources\Imports\Pages\ViewImportBatch;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ImportBatch;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Import history, scoped to the projects the user may see. Batches are
 * never edited or deleted: they are the audit trail of CSV imports.
 */
class ImportBatchResource extends Resource
{
    protected static ?string $model = ImportBatch::class;

    protected static ?string $slug = 'imports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Imports';

    protected static ?string $modelLabel = 'import';

    protected static ?string $pluralModelLabel = 'imports';

    protected static ?int $navigationSort = 50;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->accessibleBy(Filament::auth()->user())
            ->with(['project.client', 'monthlyCycle', 'createdBy']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('j M Y H:i')->sortable(),
                TextColumn::make('project.name')->label('Project')
                    ->description(fn (ImportBatch $record): ?string => $record->project?->client?->name)
                    ->url(fn (ImportBatch $record): ?string => $record->project ? ProjectResource::getUrl('view', ['record' => $record->project]) : null),
                TextColumn::make('period')->label('Period')
                    ->state(fn (ImportBatch $record): string => $record->monthlyCycle?->periodLabel() ?? '—'),
                TextColumn::make('import_type')->label('Type')->badge()->color('gray'),
                TextColumn::make('original_filename')->label('Filename')->limit(40),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('total_rows')->label('Total')->alignRight(),
                TextColumn::make('imported_rows')->label('Imported')->alignRight(),
                TextColumn::make('failed_rows')->label('Failed')->alignRight()
                    ->color(fn (ImportBatch $record): ?string => $record->failed_rows > 0 ? 'danger' : null),
                TextColumn::make('createdBy.name')->label('Created by'),
            ])
            ->filters([
                SelectFilter::make('project_id')->label('Project')
                    ->options(fn (): array => Project::query()->accessibleBy(Filament::auth()->user())->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('import_type')->label('Type')->options(ImportType::class),
                SelectFilter::make('status')->label('Status')->options(ImportStatus::class),
            ])
            ->recordActions([
                Action::make('view')->label('View details')->icon(Heroicon::OutlinedEye)
                    ->url(fn (ImportBatch $record): string => static::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No imports yet')
            ->emptyStateDescription('Start a new import to load keywords, rankings, backlinks or analytics from a CSV file.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportBatches::route('/'),
            'create' => NewImport::route('/new'),
            'view' => ViewImportBatch::route('/{record}'),
        ];
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
