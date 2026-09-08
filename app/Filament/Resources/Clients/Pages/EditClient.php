<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\Actions\ArchiveAction;
use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ArchiveAction::make()
                ->successRedirectUrl(fn (Client $record): string => static::getResource()::getUrl('view', ['record' => $record])),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
