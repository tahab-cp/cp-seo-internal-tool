<?php

namespace Database\Factories;

use App\Models\Ga4CountryMetric;
use App\Models\MonthlyCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ga4CountryMetric>
 */
class Ga4CountryMetricFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_cycle_id' => MonthlyCycle::factory(),
            'country' => fake()->unique()->country(),
            'active_users' => fake()->numberBetween(0, 5000),
            'new_users' => fake()->numberBetween(0, 2500),
            'sessions' => fake()->numberBetween(0, 8000),
            'engaged_sessions' => fake()->numberBetween(0, 4000),
            'engagement_rate' => fake()->randomFloat(2, 0, 100),
            'event_count' => fake()->numberBetween(0, 20000),
            'key_events' => fake()->numberBetween(0, 200),
        ];
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => ['monthly_cycle_id' => $cycle->getKey()]);
    }
}
