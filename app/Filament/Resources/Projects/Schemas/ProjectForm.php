<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Project')
                    ->description('One SEO engagement or website. Packages, targets and monthly cycles are configured in later steps.')
                    ->columns(2)
                    ->components([
                        Select::make('client_id')
                            ->label('Client')
                            ->relationship(
                                name: 'client',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label('Project name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('website_url')
                            ->label('Website URL')
                            ->url()
                            ->required()
                            ->maxLength(255)
                            ->placeholder('https://example.com'),
                        Select::make('status')
                            ->options(ProjectStatus::class)
                            ->required()
                            ->default(ProjectStatus::Onboarding->value)
                            ->native(false),
                        TextInput::make('target_location')
                            ->label('Target location')
                            ->maxLength(255),
                        DatePicker::make('start_date')
                            ->label('Start date')
                            ->native(false),
                        DatePicker::make('end_date')
                            ->label('End date')
                            ->native(false)
                            ->afterOrEqual('start_date'),
                    ]),
                Section::make('Team')
                    ->description('The primary owner is automatically part of the team and is never listed twice.')
                    ->columns(2)
                    ->components([
                        Select::make('primary_seo_user_id')
                            ->label('Primary SEO owner')
                            ->relationship(
                                name: 'primarySeoUser',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->rule(Rule::exists('users', 'id')->where('is_active', true))
                            ->disabled(fn (?Project $record): bool => ! static::canAssignTeam($record))
                            ->dehydrated(fn (?Project $record): bool => static::canAssignTeam($record)),
                        Select::make('team_member_ids')
                            ->label('Additional team members')
                            ->multiple()
                            ->options(fn (): array => User::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->nestedRecursiveRules([
                                'integer',
                                'distinct',
                                Rule::exists('users', 'id')->where('is_active', true),
                            ])
                            ->disabled(fn (?Project $record): bool => ! static::canAssignTeam($record))
                            ->dehydrated(fn (?Project $record): bool => static::canAssignTeam($record)),
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

    /**
     * On create the policy's `create` check gates the page; on edit the
     * actor must hold the assignTeam ability for this record.
     */
    protected static function canAssignTeam(?Project $record): bool
    {
        return $record === null || Filament::auth()->user()?->can('assignTeam', $record) === true;
    }
}
