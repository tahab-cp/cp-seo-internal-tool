<?php

namespace Database\Factories;

use App\Enums\MonthlyCycleStatus;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates bare cycles (no target snapshot). Use the actions when the
 * snapshot matters.
 *
 * @extends Factory<MonthlyCycle>
 */
class MonthlyCycleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $period = CyclePeriod::current();

        return [
            'project_id' => Project::factory(),
            'year' => $period->year,
            'month' => $period->month,
            'status' => MonthlyCycleStatus::Open,
            'started_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->getKey(),
        ]);
    }

    public function forPeriod(CyclePeriod $period): static
    {
        return $this->state(fn (array $attributes) => [
            'year' => $period->year,
            'month' => $period->month,
        ]);
    }
}
