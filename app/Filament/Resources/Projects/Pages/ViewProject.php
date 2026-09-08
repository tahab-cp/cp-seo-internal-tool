<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Actions\ArchiveAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('monthlyCycles')
                ->label('Monthly cycles')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('monthly-cycles', ['record' => $record])),
            EditAction::make()
                ->hidden(fn (Project $record): bool => $record->trashed()),
            ArchiveAction::make(),
            RestoreAction::make()
                ->successNotificationTitle('Project restored'),
        ];
    }
}
