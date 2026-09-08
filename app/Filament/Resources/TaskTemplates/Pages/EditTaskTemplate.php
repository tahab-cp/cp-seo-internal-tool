<?php

namespace App\Filament\Resources\TaskTemplates\Pages;

use App\Actions\TaskTemplates\UpdateTaskTemplateAction;
use App\Filament\Resources\TaskTemplates\Actions\TaskTemplateStatusActions;
use App\Filament\Resources\TaskTemplates\TaskTemplateResource;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateItem;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditTaskTemplate extends EditRecord
{
    protected static string $resource = TaskTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TaskTemplateStatusActions::deactivate(),
            TaskTemplateStatusActions::activate(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var TaskTemplate $template */
        $template = $this->getRecord();

        $data['items'] = $template->items
            ->map(fn (TaskTemplateItem $item): array => [
                'id' => $item->id,
                'phase' => $item->phase,
                'title' => $item->title,
                'description' => $item->description,
                'category' => $item->category,
                'default_due_days' => $item->default_due_days,
                'sort_order' => $item->sort_order,
            ])
            ->values()
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TaskTemplate $record */
        return app(UpdateTaskTemplateAction::class)->handle(
            $record,
            Arr::only($data, ['name', 'description', 'is_active']),
            array_values($data['items'] ?? []),
        );
    }
}
