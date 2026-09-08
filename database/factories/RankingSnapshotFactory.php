<?php

namespace Database\Factories;

use App\Enums\RankingSource;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\RankingSnapshot;
use App\Support\MonthlyCycles\CyclePeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Bare persistence for tests. Domain rules (same-project cycle, lock,
 * position, dedupe) live in the actions.
 *
 * @extends Factory<RankingSnapshot>
 */
class RankingSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'keyword_id' => Keyword::factory(),
            // Derived from the keyword's project in configure() when omitted.
            'monthly_cycle_id' => null,
            'checked_at' => now(),
            'position' => fake()->numberBetween(1, 50),
            'ranking_url' => null,
            'source' => RankingSource::Manual,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (RankingSnapshot $snapshot): void {
            if ($snapshot->monthly_cycle_id !== null) {
                return;
            }

            $keyword = Keyword::withTrashed()->findOrFail($snapshot->keyword_id);
            $period = CyclePeriod::fromDate($snapshot->checked_at ?? now());

            $snapshot->monthly_cycle_id = (
                $keyword->project->monthlyCycles()->forPeriod($period)->first()
                ?? MonthlyCycle::factory()->forProject($keyword->project)->forPeriod($period)->create()
            )->getKey();
        });
    }

    public function forKeyword(Keyword $keyword): static
    {
        return $this->state(fn (array $attributes) => [
            'keyword_id' => $keyword->getKey(),
        ]);
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => [
            'monthly_cycle_id' => $cycle->getKey(),
        ]);
    }

    public function at(CarbonInterface|string $checkedAt, ?int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'checked_at' => Carbon::parse($checkedAt),
            'position' => $position,
        ]);
    }

    public function notRanking(): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => null,
        ]);
    }
}
