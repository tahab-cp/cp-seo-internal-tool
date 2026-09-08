<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * The four initial target keys from the product spec.
     *
     * @var list<array{target_key: string, label: string, target_value: int}>
     */
    public const DEFAULT_TARGETS = [
        ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
        ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 8],
        ['target_key' => 'pages_optimized', 'label' => 'Pages Optimised', 'target_value' => 8],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()).'+',
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * @param  list<array{target_key: string, label: string, target_value: int, sort_order?: int}>|null  $targets
     */
    public function withTargets(?array $targets = null): static
    {
        $targets ??= self::DEFAULT_TARGETS;

        return $this->afterCreating(function (Package $package) use ($targets): void {
            foreach (array_values($targets) as $index => $target) {
                $package->targets()->create([
                    'target_key' => $target['target_key'],
                    'label' => $target['label'],
                    'target_value' => $target['target_value'],
                    'sort_order' => $target['sort_order'] ?? $index,
                ]);
            }

            $package->unsetRelation('targets');
        });
    }
}
