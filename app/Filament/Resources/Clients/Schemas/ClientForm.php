<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\ClientStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client')
                    ->description('Clients are agency accounts. SEO work and reporting live on the client\'s projects.')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label('Client name')
                            ->required()
                            ->maxLength(255),
                        Select::make('status')
                            ->options(ClientStatus::class)
                            ->required()
                            ->default(ClientStatus::Active->value)
                            ->native(false),
                        TextInput::make('company_name')
                            ->label('Company name')
                            ->maxLength(255),
                        TextInput::make('contact_name')
                            ->label('Primary contact')
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50),
                        Select::make('account_manager_id')
                            ->label('Account manager')
                            ->relationship(
                                name: 'accountManager',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            // The relationship already limits the options; this makes the
                            // "active user" requirement explicit server-side as well.
                            ->rule(Rule::exists('users', 'id')->where('is_active', true))
                            ->helperText('Only active users can be assigned.'),
                    ]),
                Section::make('Internal notes')
                    ->components([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->rows(5)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
