<?php

namespace App\Filament\Resources\Projects\Actions;

use App\Actions\Projects\ArchiveProjectAction;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Shared "Archive" action for the projects table and record pages.
 * Delegates to App\Actions\Projects\ArchiveProjectAction (a soft delete).
 */
class ArchiveAction
{
    public static function make(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Archive project')
            ->modalDescription('The project is hidden from day-to-day lists but keeps its client, team and history, and can be restored later. Nothing is permanently deleted.')
            ->visible(fn (Project $record): bool => ! $record->trashed())
            ->authorize('archive')
            ->successNotificationTitle('Project archived')
            ->action(function (Project $record, Action $action): void {
                // Re-check server-side so a crafted request cannot bypass the policy.
                Gate::authorize('archive', $record);

                app(ArchiveProjectAction::class)->handle($record);

                $action->success();
            });
    }
}
