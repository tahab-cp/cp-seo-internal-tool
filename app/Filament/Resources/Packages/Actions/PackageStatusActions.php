<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Actions\Packages\SetPackageActiveStatusAction;
use App\Models\Package;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Shared Activate / Deactivate actions for the packages table and edit page.
 */
class PackageStatusActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Deactivate package')
            ->modalDescription('The package can no longer be assigned to new projects. Projects already using it keep it, and its targets are unchanged.')
            ->visible(fn (Package $record): bool => $record->is_active)
            ->authorize('deactivate')
            ->successNotificationTitle('Package deactivated')
            ->action(function (Package $record, Action $action): void {
                Gate::authorize('deactivate', $record);

                app(SetPackageActiveStatusAction::class)->handle($record, false);

                $action->success();
            });
    }

    public static function activate(): Action
    {
        return Action::make('activate')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Package $record): bool => ! $record->is_active)
            ->authorize('activate')
            ->successNotificationTitle('Package activated')
            ->action(function (Package $record, Action $action): void {
                Gate::authorize('activate', $record);

                app(SetPackageActiveStatusAction::class)->handle($record, true);

                $action->success();
            });
    }
}
