<?php

namespace Database\Factories;

use App\Enums\DataSource;
use App\Models\Ga4MonthlyMetric;
use App\Models\MonthlyCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ga4MonthlyMetric>
 */
class Ga4MonthlyMetricFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_cycle_id' => MonthlyCycle::factory(),
            'active_users' => fake()->numberBetween(0, 20000),
            'new_users' => fake()->numberBetween(0, 10000),
            'sessions' => fake()->numberBetween(0, 30000),
            'organic_sessions' => fake()->numberBetween(0, 15000),
            'engaged_sessions' => fake()->numberBetween(0, 15000),
            'engagement_rate' => fake()->randomFloat(2, 0, 100),
            'average_engagement_time_seconds' => fake()->numberBetween(0, 600),
            'event_count' => fake()->numberBetween(0, 100000),
            'key_events' => fake()->numberBetween(0, 500),
            'source' => DataSource::Manual,
            'synced_at' => null,
            'entered_by' => null,
        ];
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => ['monthly_cycle_id' => $cycle->getKey()]);
    }
}
