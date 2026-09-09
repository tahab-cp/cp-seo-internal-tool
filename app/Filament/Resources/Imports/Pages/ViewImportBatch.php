<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Imports\ImportBatchResource;
use App\Models\ImportBatch;
use App\Models\ImportRowError;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Import details: the batch summary and its reviewable row problems.
 * Reached only through the scoped resource query (404 outside scope).
 */
class ViewImportBatch extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ImportBatchResource::class;

    protected string $view = 'filament.resources.imports.pages.view-import-batch';

    protected static ?string $title = 'Import details';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getBatch(): ImportBatch
    {
        /** @var ImportBatch $batch */
        $batch = $this->getRecord();

        return $batch;
    }

    public function getSubheading(): ?string
    {
        $batch = $this->getBatch();

        return $batch->project->name.' — '.$batch->import_type->getLabel().($batch->monthlyCycle ? ' — '.$batch->monthlyCycle->periodLabel() : '');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ImportRowError::query()->where('import_batch_id', $this->getBatch()->getKey())->orderBy('row_number')->orderBy('id'))
            ->columns([
                TextColumn::make('row_number')->label('Row')
                    ->state(fn (ImportRowError $record): string => $record->row_number > 0 ? (string) $record->row_number : 'File'),
                TextColumn::make('severity')->label('Severity')->badge()
                    ->color(fn (ImportRowError $record): string => $record->isWarning() ? 'warning' : 'danger'),
                TextColumn::make('field')->label('Field')->placeholder('—'),
                TextColumn::make('message')->label('Reason')->wrap(),
            ])
            ->filters([
                SelectFilter::make('severity')->options([
                    ImportRowError::SEVERITY_ERROR => 'Errors',
                    ImportRowError::SEVERITY_WARNING => 'Warnings',
                ]),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No problems recorded')
            ->emptyStateDescription('Every row of this import passed validation.');
    }
}
