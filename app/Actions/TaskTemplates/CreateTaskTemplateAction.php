<?php

namespace App\Actions\TaskTemplates;

use App\Models\TaskTemplate;
use Illuminate\Support\Facades\DB;

class CreateTaskTemplateAction
{
    public function __construct(
        protected SyncTaskTemplateItemsAction $syncItems,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, is_active?: bool}  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    public function handle(array $attributes, array $items = []): TaskTemplate
    {
        return DB::transaction(function () use ($attributes, $items): TaskTemplate {
            $template = TaskTemplate::query()->create([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'is_active' => $attributes['is_active'] ?? true,
            ]);

            return $this->syncItems->handle($template, $items);
        });
    }
}
