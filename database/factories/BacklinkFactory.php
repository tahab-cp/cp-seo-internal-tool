<?php

namespace Database\Factories;

use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Models\Backlink;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare persistence for tests. Domain rules (same-project cycle, lock,
 * URL/metric validation, creator eligibility) live in the actions.
 *
 * @extends Factory<Backlink>
 */
class BacklinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_cycle_id' => MonthlyCycle::factory(),
            // Derived from the cycle in configure().
            'project_id' => null,
            'created_by' => User::factory(),
            'published_date' => fake()->optional()->dateTimeBetween('-30 days', 'now')?->format('Y-m-d'),
            'published_url' => 'https://'.fake()->domainName().'/'.fake()->slug(2),
            'anchor_text' => fake()->optional()->words(2, true),
            'target_url' => null,
            'type' => BacklinkType::Citation,
            'status' => BacklinkStatus::Live,
            'domain_authority' => fake()->optional()->numberBetween(1, 90),
            'domain_rating' => fake()->optional()->numberBetween(1, 90),
            'spam_score' => fake()->optional()->numberBetween(0, 20),
            'notes' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Backlink $backlink): void {
            $backlink->project_id ??= MonthlyCycle::query()->findOrFail($backlink->monthly_cycle_id)->project_id;
        });
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => [
            'monthly_cycle_id' => $cycle->getKey(),
            'project_id' => $cycle->project_id,
        ]);
    }

    /**
     * Attach to the project's current-period cycle (creating it if needed).
     */
    public function forProject(Project $project): static
    {
        return $this->state(function (array $attributes) use ($project): array {
            $cycle = $project->monthlyCycles()->forPeriod(CyclePeriod::current())->first()
                ?? MonthlyCycle::factory()->forProject($project)->create();

            return ['monthly_cycle_id' => $cycle->getKey(), 'project_id' => $project->getKey()];
        });
    }

    public function type(BacklinkType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }

    public function status(BacklinkStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function guestPost(): static
    {
        return $this->type(BacklinkType::GuestPost);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => ['created_by' => $user->getKey()]);
    }
}
