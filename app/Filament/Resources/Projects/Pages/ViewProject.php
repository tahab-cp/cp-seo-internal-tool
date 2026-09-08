<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Actions\ArchiveAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->hidden(fn (Project $record): bool => $record->trashed()),
            ArchiveAction::make(),
            RestoreAction::make()
                ->successNotificationTitle('Project restored'),
        ];
    }
}
