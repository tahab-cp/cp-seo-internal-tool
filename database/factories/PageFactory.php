<?php

namespace Database\Factories;

use App\Enums\PageStatus;
use App\Models\Page;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = '/'.fake()->unique()->slug(2);

        return [
            'project_id' => Project::factory(),
            'url' => 'https://'.fake()->domainName().$path,
            'path' => $path,
            'title' => ucfirst(fake()->words(3, true)),
            'page_type' => fake()->optional()->randomElement(['service', 'landing', 'blog', 'location']),
            'status' => PageStatus::Active,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->getKey(),
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PageStatus::Removed,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PageStatus::Draft,
        ]);
    }
}
