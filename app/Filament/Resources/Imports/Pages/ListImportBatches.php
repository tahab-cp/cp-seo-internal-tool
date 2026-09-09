<?php

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Imports\ImportBatchResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected static ?string $title = 'Imports';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newImport')
                ->label('New import')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(ImportBatchResource::getUrl('create')),
        ];
    }
}
