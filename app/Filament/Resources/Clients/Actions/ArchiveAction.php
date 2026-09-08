<?php

namespace App\Filament\Resources\Clients\Actions;

use App\Actions\Clients\ArchiveClientAction;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Shared "Archive" action for the clients table and record pages.
 * Delegates to App\Actions\Clients\ArchiveClientAction.
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
            ->modalHeading('Archive client')
            ->modalDescription('The client will be marked as archived and kept for history. Nothing is deleted.')
            ->visible(fn (Client $record): bool => ! $record->isArchived())
            ->authorize('archive')
            ->successNotificationTitle('Client archived')
            ->action(function (Client $record, Action $action): void {
                // Re-check server-side so a crafted request cannot bypass the policy.
                Gate::authorize('archive', $record);

                app(ArchiveClientAction::class)->handle($record);

                $action->success();
            });
    }
}
