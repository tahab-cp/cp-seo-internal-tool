<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->unique()->words(2, true),
            'website_url' => 'https://'.fake()->unique()->domainName(),
            'target_location' => fake()->optional()->city(),
            'status' => ProjectStatus::Active,
            'start_date' => fake()->optional()->dateTimeBetween('-1 year', 'now'),
            'end_date' => null,
            'primary_seo_user_id' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function withPackage(Package $package): static
    {
        return $this->state(fn (array $attributes) => [
            'package_id' => $package->getKey(),
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn (array $attributes) => [
            'client_id' => $client->getKey(),
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'primary_seo_user_id' => $user->getKey(),
        ]);
    }

    /**
     * @param  list<User>  $users
     */
    public function withTeam(array $users): static
    {
        return $this->afterCreating(function (Project $project) use ($users): void {
            $project->teamMembers()->attach(
                collect($users)->map(fn (User $user) => $user->getKey())->all(),
            );
        });
    }

    public function onboarding(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Onboarding,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Paused,
        ]);
    }
}
