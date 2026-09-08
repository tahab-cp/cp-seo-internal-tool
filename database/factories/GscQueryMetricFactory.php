<?php

namespace Database\Factories;

use App\Models\GscQueryMetric;
use App\Models\MonthlyCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GscQueryMetric>
 */
class GscQueryMetricFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_cycle_id' => MonthlyCycle::factory(),
            'query' => fake()->unique()->words(3, true),
            'clicks' => fake()->numberBetween(0, 500),
            'impressions' => fake()->numberBetween(0, 20000),
            'ctr' => fake()->randomFloat(2, 0, 20),
            'average_position' => fake()->randomFloat(2, 1, 60),
        ];
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => ['monthly_cycle_id' => $cycle->getKey()]);
    }
}
