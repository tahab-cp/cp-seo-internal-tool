<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\Users\SetUserActiveStatusAction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->state(fn (User $record) => $record->role())
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('email_verified_at')
                    ->label('Verified')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->relationship('roles', 'name'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('deactivate')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The user will keep their history but can no longer sign in.')
                    ->visible(fn (User $record): bool => $record->is_active)
                    ->authorize('deactivate')
                    ->successNotificationTitle('User deactivated')
                    ->action(function (User $record, Action $action): void {
                        // Re-check server-side so a crafted request cannot bypass the policy.
                        Gate::authorize('deactivate', $record);

                        app(SetUserActiveStatusAction::class)->handle($record, false);

                        $action->success();
                    }),
                Action::make('activate')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => ! $record->is_active)
                    ->authorize('activate')
                    ->successNotificationTitle('User activated')
                    ->action(function (User $record, Action $action): void {
                        Gate::authorize('activate', $record);

                        app(SetUserActiveStatusAction::class)->handle($record, true);

                        $action->success();
                    }),
            ])
            ->toolbarActions([]);
    }
}
