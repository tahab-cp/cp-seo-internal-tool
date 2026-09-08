<?php

namespace Database\Factories;

use App\Models\TaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    /**
     * @var list<array{title: string, description?: string|null, category?: string|null, phase?: string|null, default_due_days?: int|null, sort_order?: int}>
     */
    public const DEFAULT_ITEMS = [
        ['title' => 'Configure Google Search Console', 'description' => 'Verify the property and submit the sitemap.', 'category' => 'Technical', 'phase' => 'Setup', 'default_due_days' => 3],
        ['title' => 'Configure GA4', 'description' => null, 'category' => 'Technical', 'phase' => 'Setup', 'default_due_days' => 3],
        ['title' => 'Initial technical audit', 'description' => 'Crawl and document issues.', 'category' => 'Audit', 'phase' => 'Discovery', 'default_due_days' => 14],
        ['title' => 'Keyword research', 'description' => null, 'category' => 'Research', 'phase' => 'Discovery', 'default_due_days' => null],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)).' onboarding',
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     */
    public function withItems(?array $items = null): static
    {
        $items ??= self::DEFAULT_ITEMS;

        return $this->afterCreating(function (TaskTemplate $template) use ($items): void {
            foreach (array_values($items) as $index => $item) {
                $template->items()->create([
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'category' => $item['category'] ?? null,
                    'phase' => $item['phase'] ?? null,
                    'default_due_days' => $item['default_due_days'] ?? null,
                    'sort_order' => $item['sort_order'] ?? $index,
                ]);
            }

            $template->unsetRelation('items');
        });
    }
}
