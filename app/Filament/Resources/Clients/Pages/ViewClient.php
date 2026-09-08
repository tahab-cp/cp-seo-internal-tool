<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\Actions\ArchiveAction;
use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ArchiveAction::make(),
        ];
    }
}
