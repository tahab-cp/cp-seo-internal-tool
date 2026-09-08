<?php

namespace App\Actions\TaskTemplates;

use App\Models\TaskTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncTaskTemplateItemsAction
{
    /**
     * Replace the template's items with the given definitions.
     *
     * Items carrying an existing id are updated in place (so tasks generated
     * from them keep their link); items without an id are created; existing
     * items missing from the list are removed. Tasks already generated from a
     * removed item are untouched (their task_template_item_id becomes null).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function handle(TaskTemplate $template, array $items): TaskTemplate
    {
        $definitions = $this->normalise($items);

        DB::transaction(function () use ($template, $definitions): void {
            $keptIds = $definitions->pluck('id')->filter()->all();

            $template->items()->whereNotIn('id', $keptIds)->delete();

            foreach ($definitions as $definition) {
                $attributes = collect($definition)->except('id')->all();

                if ($definition['id'] !== null) {
                    $template->items()->whereKey($definition['id'])->update($attributes);
                } else {
                    $template->items()->create($attributes);
                }
            }
        });

        $template->unsetRelation('items');

        return $template;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, array{id: int|null, title: string, description: string|null, category: string|null, phase: string|null, default_due_days: int|null, sort_order: int}>
     */
    protected function normalise(array $items): Collection
    {
        return collect(array_values($items))->map(function (array $item, int $index): array {
            $title = trim((string) ($item['title'] ?? ''));

            if ($title === '' || mb_strlen($title) > 255) {
                throw new InvalidArgumentException('Every template item needs a title of at most 255 characters.');
            }

            $dueDays = $item['default_due_days'] ?? null;

            if ($dueDays !== null && $dueDays !== '') {
                if (! is_numeric($dueDays) || (int) $dueDays < 0 || (int) $dueDays != $dueDays) {
                    throw new InvalidArgumentException("Template item [{$title}] default due days must be a non-negative integer.");
                }

                $dueDays = (int) $dueDays;
            } else {
                $dueDays = null;
            }

            foreach (['category' => 100, 'phase' => 100] as $field => $max) {
                if (mb_strlen((string) ($item[$field] ?? '')) > $max) {
                    throw new InvalidArgumentException("Template item [{$title}] {$field} may not exceed {$max} characters.");
                }
            }

            return [
                'id' => filled($item['id'] ?? null) ? (int) $item['id'] : null,
                'title' => $title,
                'description' => filled($item['description'] ?? null) ? (string) $item['description'] : null,
                'category' => filled($item['category'] ?? null) ? (string) $item['category'] : null,
                'phase' => filled($item['phase'] ?? null) ? (string) $item['phase'] : null,
                'default_due_days' => $dueDays,
                'sort_order' => is_numeric($item['sort_order'] ?? null) ? max(0, (int) $item['sort_order']) : $index,
            ];
        })->values();
    }
}
