<?php

namespace Database\Factories;

use App\Enums\DataSource;
use App\Models\GscMonthlyMetric;
use App\Models\MonthlyCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GscMonthlyMetric>
 */
class GscMonthlyMetricFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_cycle_id' => MonthlyCycle::factory(),
            'clicks' => fake()->numberBetween(0, 5000),
            'impressions' => fake()->numberBetween(0, 200000),
            'ctr' => fake()->randomFloat(2, 0, 20),
            'average_position' => fake()->randomFloat(2, 1, 60),
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
