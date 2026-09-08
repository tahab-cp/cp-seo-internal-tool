<?php

namespace Database\Factories;

use App\Enums\KeywordStatus;
use App\Models\Keyword;
use App\Models\Page;
use App\Models\Project;
use App\Support\Keywords\KeywordNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Keyword>
 */
class KeywordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $keyword = fake()->unique()->words(3, true);

        return [
            'project_id' => Project::factory(),
            'keyword' => $keyword,
            'keyword_normalized' => KeywordNormalizer::keyword($keyword),
            'target_page_id' => null,
            'keyword_role' => null,
            'search_volume' => fake()->optional()->numberBetween(10, 5000),
            'keyword_difficulty' => fake()->optional()->numberBetween(1, 90),
            'search_intent' => null,
            'location' => null,
            'location_normalized' => '',
            'is_branded' => false,
            'status' => KeywordStatus::Active,
        ];
    }

    public function configure(): static
    {
        // Keep the normalised shadows consistent with explicit overrides.
        return $this->afterMaking(function (Keyword $keyword): void {
            $keyword->keyword_normalized = KeywordNormalizer::keyword($keyword->keyword);
            $keyword->location_normalized = KeywordNormalizer::location($keyword->location);
        });
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->getKey(),
        ]);
    }

    public function targeting(Page $page): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $page->project_id,
            'target_page_id' => $page->getKey(),
        ]);
    }

    public function status(KeywordStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
