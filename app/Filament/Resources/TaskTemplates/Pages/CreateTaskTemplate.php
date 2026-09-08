<?php

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Actions\TaskTemplates\CreateTaskTemplateAction;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateTaskTemplate extends CreateRecord
{
    protected static string $resource = TaskTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateTaskTemplateAction::class)->handle(
            Arr::only($data, ['name', 'description', 'is_active']),
            array_values($data['items'] ?? []),
        );
    }
}
