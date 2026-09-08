<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Projects\SyncProjectTeamAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Filament\Resources\Projects\Actions\ArchiveAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Project $record */
        $actor = Filament::auth()->user();

        return DB::transaction(function () use ($record, $data, $actor): Project {
            $attributes = Arr::except($data, ['team_member_ids']);

            if (! $actor->can('assignTeam', $record)) {
                unset($attributes['primary_seo_user_id']);
            }

            $record = app(UpdateProjectAction::class)->handle($record, $attributes);

            if (array_key_exists('team_member_ids', $data) && $actor->can('assignTeam', $record)) {
                $record = app(SyncProjectTeamAction::class)->handle($record, Arr::wrap($data['team_member_ids']));
            }

            return $record;
        });
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
