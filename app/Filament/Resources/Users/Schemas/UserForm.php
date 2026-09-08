<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->rule(Password::min(8))
                            ->confirmed()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Leave blank to keep the current password.'
                                : null),
                        TextInput::make('password_confirmation')
                            ->label('Confirm password')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get): bool => filled($get('password')))
                            ->dehydrated(false),
                    ]),
                Section::make('Access')
                    ->columns(2)
                    ->components([
                        Select::make('role')
                            ->options(UserRole::class)
                            ->required()
                            ->native(false)
                            ->disabled(fn (?User $record): bool => ! static::canAssignRole($record))
                            ->dehydrated(fn (?User $record): bool => static::canAssignRole($record))
                            ->helperText('Users cannot change their own role.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->inline(false)
                            ->disabled(fn (?User $record): bool => ! static::canChangeActiveStatus($record))
                            ->dehydrated(fn (?User $record): bool => static::canChangeActiveStatus($record))
                            ->helperText('Inactive users cannot sign in. Users cannot deactivate themselves.'),
                    ]),
            ]);
    }

    /**
     * On create the policy's `create` check already gates the page; on edit
     * the actor must be allowed to assign a role to this specific record.
     */
    protected static function canAssignRole(?User $record): bool
    {
        return $record === null || Filament::auth()->user()?->can('assignRole', $record) === true;
    }

    protected static function canChangeActiveStatus(?User $record): bool
    {
        return $record === null || Filament::auth()->user()?->can('deactivate', $record) === true;
    }
}
