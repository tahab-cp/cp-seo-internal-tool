<?php

namespace Database\Factories;

use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare persistence for tests. Domain rules (same-project page and cycle,
 * lock state, recorder eligibility, at-least-one flag) live in the actions.
 *
 * @extends Factory<PageOptimization>
 */
class PageOptimizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            // project_id and monthly_cycle_id are derived from the page in configure().
            'project_id' => null,
            'monthly_cycle_id' => null,
            'user_id' => null,
            'optimized_at' => now(),
            'meta_title_updated' => true,
            'meta_description_updated' => false,
            'content_updated' => false,
            'internal_links_updated' => false,
            'schema_updated' => false,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PageOptimization $optimization): void {
            $page = Page::withTrashed()->findOrFail($optimization->page_id);

            $optimization->project_id ??= $page->project_id;

            // Reuse the project's current-period cycle when it already exists
            // (one cycle per project/year/month).
            $optimization->monthly_cycle_id ??= (
                $page->project->monthlyCycles()->forPeriod(CyclePeriod::current())->first()
                ?? MonthlyCycle::factory()->forProject($page->project)->create()
            )->getKey();
        });
    }

    public function forPage(Page $page): static
    {
        return $this->state(fn (array $attributes) => [
            'page_id' => $page->getKey(),
            'project_id' => $page->project_id,
        ]);
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => [
            'monthly_cycle_id' => $cycle->getKey(),
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * @param  list<string>  $flags
     */
    public function changes(array $flags): static
    {
        return $this->state(fn (array $attributes) => collect(PageOptimization::CHANGE_FLAGS)
            ->keys()
            ->mapWithKeys(fn (string $flag): array => [$flag => in_array($flag, $flags, true)])
            ->all());
    }
}
