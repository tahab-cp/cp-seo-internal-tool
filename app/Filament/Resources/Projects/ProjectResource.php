<?php

namespace App\Filament\Resources\Projects;

use App\Enums\Permission;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\Schemas\ProjectForm;
use App\Filament\Resources\Projects\Schemas\ProjectInfolist;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Models\Project;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Projects (sidebar → Projects).
 *
 * Every query Filament issues for this resource — the index table, record
 * route binding for view/edit URLs, table actions and global search — goes
 * through getEloquentQuery(), which applies Project::scopeAccessibleBy().
 * Unrelated projects therefore do not exist for an SEO Executive (404),
 * and ProjectPolicy additionally guards each ability (403).
 */
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        $query = parent::getEloquentQuery();

        // Only users who may archive/restore can see archived (soft-deleted) projects.
        if ($user?->hasPermission(Permission::ArchiveProjects)) {
            $query->withoutGlobalScopes([SoftDeletingScope::class]);
        }

        return $query->accessibleBy($user);
    }

    public static function form(Schema $schema): Schema
    {
        return ProjectForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProjectInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'view' => ViewProject::route('/{record}'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'website_url', 'client.name'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Project $record */
        return array_filter([
            'Client' => $record->client?->name,
            'Website' => $record->website_url,
        ]);
    }
}
