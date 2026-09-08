<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Client → hasMany Projects, shown on the client detail page.
 *
 * Creating and editing projects happens in ProjectResource so the project
 * workflow (CreateProjectAction) stays in one place. The table query is
 * scoped with Project::scopeAccessibleBy() as defence in depth even though
 * client pages are already closed to SEO Executives.
 */
class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $title = 'Projects';

    public function table(Table $table): Table
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->accessibleBy($user)->with('primarySeoUser'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Project')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('website_url')
                    ->label('Website')
                    ->url(fn (Project $record): string => $record->website_url)
                    ->openUrlInNewTab()
                    ->limit(40),
                TextColumn::make('primarySeoUser.name')
                    ->label('Primary SEO')
                    ->placeholder('Unassigned'),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ProjectStatus::class),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('New project')
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize(fn (): bool => ProjectResource::canCreate())
                    ->url(fn (): string => ProjectResource::getUrl('create', ['client' => $this->getOwnerRecord()->getKey()])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([])
            ->emptyStateIcon(Heroicon::OutlinedRocketLaunch)
            ->emptyStateHeading('No projects for this client yet')
            ->emptyStateDescription('A client may own one or more SEO projects. Create the first project to start tracking work and reporting.');
    }
}
