<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare persistence for tests. Domain rules (same-project cycle/keyword,
 * assignee eligibility, lock, published-state requirements) live in the
 * actions.
 *
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'monthly_cycle_id' => null,
            'assigned_user_id' => null,
            'target_keyword_id' => null,
            'title' => ucfirst(fake()->unique()->words(4, true)),
            'content_type' => ContentType::Blog,
            'status' => ContentStatus::Planned,
            'planned_publish_date' => null,
            'published_at' => null,
            'published_url' => null,
            'notes' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => ['project_id' => $project->getKey()]);
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $cycle->project_id,
            'monthly_cycle_id' => $cycle->getKey(),
        ]);
    }

    public function type(ContentType $type): static
    {
        return $this->state(fn (array $attributes) => ['content_type' => $type]);
    }

    public function status(ContentStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    /**
     * A published item with the required publish details.
     */
    public function published(string $at = '2026-09-10 09:00:00', ?string $url = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContentStatus::Published,
            'published_at' => $at,
            'published_url' => $url ?? 'https://site.example/blog/'.fake()->unique()->slug(2),
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => ['assigned_user_id' => $user->getKey()]);
    }

    public function targeting(Keyword $keyword): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $keyword->project_id,
            'target_keyword_id' => $keyword->getKey(),
        ]);
    }
}
