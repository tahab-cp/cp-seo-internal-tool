<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Enums\ClientStatus;
use App\Filament\Resources\Clients\Actions\ArchiveAction;
use App\Models\Client;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('accountManager'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Client')
                    ->sortable()
                    // One search box covering client name, company, contact and email.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%")
                            ->orWhere('contact_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"),
                    )),
                TextColumn::make('company_name')
                    ->label('Company')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('contact_name')
                    ->label('Contact')
                    ->description(fn (Client $record): ?string => $record->email)
                    ->placeholder('—'),
                TextColumn::make('accountManager.name')
                    ->label('Account manager')
                    ->sortable()
                    ->placeholder('Unassigned'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ClientStatus::class),
                SelectFilter::make('account_manager_id')
                    ->label('Account manager')
                    ->relationship('accountManager', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ArchiveAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice2)
            ->emptyStateHeading('No clients yet')
            ->emptyStateDescription(
                'Clients are agency accounts. Each client may own one or more SEO projects, '
                .'and all SEO work, targets and monthly reports belong to those projects.'
            )
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }
}
