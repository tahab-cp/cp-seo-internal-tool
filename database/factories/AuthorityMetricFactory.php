<?php

namespace Database\Factories;

use App\Enums\DataSource;
use App\Models\AuthorityMetric;
use App\Models\MonthlyCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthorityMetric>
 */
class AuthorityMetricFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_cycle_id' => MonthlyCycle::factory(),
            'moz_domain_authority' => fake()->numberBetween(1, 80),
            'moz_linking_root_domains' => fake()->numberBetween(0, 5000),
            'ahrefs_domain_rating' => fake()->randomFloat(1, 0, 90),
            'ahrefs_url_rating' => fake()->randomFloat(1, 0, 90),
            'backlinks_count' => fake()->numberBetween(0, 100000),
            'referring_domains_count' => fake()->numberBetween(0, 5000),
            'source' => DataSource::Manual,
            'synced_at' => null,
            'entered_by' => null,
            'notes' => null,
        ];
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => ['monthly_cycle_id' => $cycle->getKey()]);
    }
}
