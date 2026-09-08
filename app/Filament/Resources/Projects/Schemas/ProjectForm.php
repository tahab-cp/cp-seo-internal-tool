<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\ProjectStatus;
use App\Models\Package;
use App\Models\PackageTarget;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Project')
                    ->description('One SEO engagement or website. Monthly cycles are configured in later steps.')
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
                Section::make('Package & targets')
                    ->description('The package supplies default monthly targets. Overrides apply to this project only and are cleared whenever the package changes.')
                    ->columns(2)
                    ->components([
                        Select::make('package_id')
                            ->label('Package')
                            ->relationship(
                                name: 'package',
                                titleAttribute: 'name',
                                // Active packages, plus the record's current package even if it is now inactive.
                                modifyQueryUsing: fn (Builder $query, ?Project $record) => $query
                                    ->where(fn (Builder $query) => $query
                                        ->where('is_active', true)
                                        ->when($record?->package_id, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
                                    ->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Package $record): string => $record->is_active
                                ? $record->name
                                : "{$record->name} (inactive)")
                            ->searchable()
                            ->preload()
                            ->live()
                            // Required for application-created projects; legacy records without a package may stay that way.
                            ->required(fn (?Project $record): bool => $record === null || $record->package_id !== null)
                            ->rule(fn (?Project $record) => Rule::exists('packages', 'id')->where(
                                fn (QueryBuilder $query) => $query
                                    ->where('is_active', true)
                                    ->when($record?->package_id, fn (QueryBuilder $query, int $id) => $query->orWhere('id', $id)),
                            ))
                            ->disabled(fn (?Project $record): bool => ! static::canAssignPackage($record))
                            ->dehydrated(fn (?Project $record): bool => static::canAssignPackage($record))
                            ->helperText('Only active packages can be assigned.')
                            ->columnSpanFull(),
                        Repeater::make('target_overrides')
                            ->label('Target overrides')
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel('Add override')
                            ->visible(fn (Get $get): bool => filled($get('package_id')))
                            ->disabled(fn (?Project $record): bool => ! static::canManageTargets($record))
                            ->dehydrated(fn (?Project $record): bool => static::canManageTargets($record))
                            ->itemLabel(fn (array $state, Get $get): ?string => static::packageTargets($get('package_id'))
                                ->get($state['target_key'] ?? '')?->label)
                            ->schema([
                                Select::make('target_key')
                                    ->label('Target')
                                    ->required()
                                    ->distinct()
                                    ->native(false)
                                    ->options(fn (Get $get): array => static::packageTargets($get('../../package_id'))
                                        ->map(fn (PackageTarget $target): string => "{$target->label} (package default {$target->target_value})")
                                        ->all())
                                    ->in(fn (Get $get): array => static::packageTargets($get('../../package_id'))->keys()->all()),
                                TextInput::make('target_value')
                                    ->label('Override value')
                                    ->required()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue(1_000_000),
                            ])
                            ->columnSpanFull(),
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
     * Targets of the package currently selected in the form, keyed by target_key.
     *
     * @return Collection<string, PackageTarget>
     */
    protected static function packageTargets(mixed $packageId): Collection
    {
        if (blank($packageId)) {
            return collect();
        }

        return PackageTarget::query()
            ->where('package_id', (int) $packageId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('target_key');
    }

    /**
     * On create the policy's `create` check gates the page; on edit the
     * actor must hold the specific ability for this record.
     */
    protected static function canAssignTeam(?Project $record): bool
    {
        return $record === null || Filament::auth()->user()?->can('assignTeam', $record) === true;
    }

    protected static function canAssignPackage(?Project $record): bool
    {
        return $record === null || Filament::auth()->user()?->can('assignPackage', $record) === true;
    }

    protected static function canManageTargets(?Project $record): bool
    {
        return $record === null || Filament::auth()->user()?->can('manageTargets', $record) === true;
    }
}
