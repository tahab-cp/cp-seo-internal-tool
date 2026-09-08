<?php

namespace Database\Factories;

use App\Enums\MonthlyNoteType;
use App\Models\MonthlyCycle;
use App\Models\MonthlyNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyNote>
 */
class MonthlyNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monthly_cycle_id' => MonthlyCycle::factory(),
            'type' => MonthlyNoteType::Observation,
            'title' => null,
            'body' => fake()->sentence(8),
            'sort_order' => 0,
            'created_by' => User::factory(),
        ];
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => ['monthly_cycle_id' => $cycle->getKey()]);
    }

    public function type(MonthlyNoteType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes) => ['created_by' => $user->getKey()]);
    }
}
