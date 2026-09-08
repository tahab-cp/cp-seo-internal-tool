<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Projects\CreateProjectAction;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    /**
     * Support "New project" links from a client page: /admin/projects/create?client=ID
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $clientId = request()->integer('client');

        if ($clientId > 0) {
            $this->form->fill(array_merge($this->form->getRawState(), ['client_id' => $clientId]));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateProjectAction::class)->handle(
            Arr::except($data, ['team_member_ids']),
            Arr::wrap($data['team_member_ids'] ?? []),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
