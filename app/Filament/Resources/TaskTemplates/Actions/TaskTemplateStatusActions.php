<?php

namespace App\Filament\Resources\TaskTemplates\Actions;

use App\Actions\TaskTemplates\SetTaskTemplateActiveStatusAction;
use App\Models\TaskTemplate;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Shared Activate / Deactivate actions for the task templates table and edit page.
 */
class TaskTemplateStatusActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Deactivate template')
            ->modalDescription('The template can no longer be used for new onboarding generation. Tasks already generated from it are unchanged.')
            ->visible(fn (TaskTemplate $record): bool => $record->is_active)
            ->authorize('deactivate')
            ->successNotificationTitle('Template deactivated')
            ->action(function (TaskTemplate $record, Action $action): void {
                Gate::authorize('deactivate', $record);

                app(SetTaskTemplateActiveStatusAction::class)->handle($record, false);

                $action->success();
            });
    }

    public static function activate(): Action
    {
        return Action::make('activate')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (TaskTemplate $record): bool => ! $record->is_active)
            ->authorize('activate')
            ->successNotificationTitle('Template activated')
            ->action(function (TaskTemplate $record, Action $action): void {
                Gate::authorize('activate', $record);

                app(SetTaskTemplateActiveStatusAction::class)->handle($record, true);

                $action->success();
            });
    }
}
