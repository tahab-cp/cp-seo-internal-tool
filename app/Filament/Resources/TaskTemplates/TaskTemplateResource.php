<?php

namespace App\Filament\Resources\TaskTemplates;

use App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate;
use App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates;
use App\Filament\Resources\TaskTemplates\Schemas\TaskTemplateForm;
use App\Filament\Resources\TaskTemplates\Tables\TaskTemplatesTable;
use App\Models\TaskTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Onboarding checklists (Settings → Task Templates). Super Admin and SEO
 * Manager via App\Policies\TaskTemplatePolicy, enforced on navigation,
 * pages and actions.
 */
class TaskTemplateResource extends Resource
{
    protected static ?string $model = TaskTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 95;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TaskTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaskTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaskTemplates::route('/'),
            'create' => CreateTaskTemplate::route('/create'),
            'edit' => EditTaskTemplate::route('/{record}/edit'),
        ];
    }
}
