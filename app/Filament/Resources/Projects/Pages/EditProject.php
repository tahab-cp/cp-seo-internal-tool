<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Projects\UpdateProjectAction;
use App\Filament\Resources\Projects\Actions\ArchiveAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\ProjectTargetOverride;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ArchiveAction::make()
                ->successRedirectUrl(fn (Project $record): string => static::getResource()::getUrl('view', ['record' => $record])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Project $project */
        $project = $this->getRecord();

        $data['team_member_ids'] = $project->teamMembers()->pluck('users.id')->all();

        $data['target_overrides'] = $project->targetOverrides()
            ->get()
            ->map(fn (ProjectTargetOverride $override): array => [
                'target_key' => $override->target_key,
                'target_value' => $override->target_value,
            ])
            ->values()
            ->all();

        return $data;
    }

    /**
     * The page only decides which parts the actor may change (update,
     * assignTeam, assignPackage, manageTargets). UpdateProjectAction owns
     * the single transaction that applies all of them or none.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Project $record */
        $actor = Filament::auth()->user();

        $attributes = Arr::except($data, ['team_member_ids', 'target_overrides']);

        if (! $actor->can('assignTeam', $record)) {
            unset($attributes['primary_seo_user_id']);
        }

        if (! $actor->can('assignPackage', $record)) {
            unset($attributes['package_id']);
        }

        $teamMemberIds = array_key_exists('team_member_ids', $data) && $actor->can('assignTeam', $record)
            ? Arr::wrap($data['team_member_ids'])
            : null;

        $targetOverrides = array_key_exists('target_overrides', $data) && $actor->can('manageTargets', $record)
            ? collect($data['target_overrides'])->pluck('target_value', 'target_key')->all()
            : null;

        return app(UpdateProjectAction::class)->handle($record, $attributes, $teamMemberIds, $targetOverrides);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
