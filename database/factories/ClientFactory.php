<?php

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'company_name' => fake()->company().' Ltd',
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'status' => ClientStatus::Active,
            'account_manager_id' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClientStatus::Inactive,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClientStatus::Archived,
        ]);
    }

    public function managedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'account_manager_id' => $user->getKey(),
        ]);
    }
}
