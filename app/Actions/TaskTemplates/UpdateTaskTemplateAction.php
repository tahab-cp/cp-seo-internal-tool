<?php

namespace App\Actions\TaskTemplates;

use App\Models\TaskTemplate;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateTaskTemplateAction
{
    public function __construct(
        protected SyncTaskTemplateItemsAction $syncItems,
    ) {}

    /**
     * Items are only touched when a list is supplied (null leaves them alone).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $items
     */
    public function handle(TaskTemplate $template, array $attributes, ?array $items = null): TaskTemplate
    {
        return DB::transaction(function () use ($template, $attributes, $items): TaskTemplate {
            $template->fill(Arr::only($attributes, ['name', 'description', 'is_active']))->save();

            if ($items !== null) {
                $template = $this->syncItems->handle($template, $items);
            }

            return $template;
        });
    }
}
