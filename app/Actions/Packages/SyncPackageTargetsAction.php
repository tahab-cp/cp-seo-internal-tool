<?php

namespace App\Actions\Packages;

use App\Models\Package;
use App\Models\PackageTarget;
use App\Models\ProjectTargetOverride;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncPackageTargetsAction
{
    /**
     * Replace the package's targets with the given definitions.
     *
     * - Targets are matched by target_key: existing rows are updated, new
     *   keys created, missing keys removed.
     * - Keys must be unique slugs; values must be non-negative integers.
     * - Project overrides for keys the package no longer defines are pruned
     *   from every project on this package, so overrides never reference
     *   keys outside their package.
     *
     * @param  list<array{target_key: string, label: string, target_value: int|string, sort_order?: int|string|null}>  $targets
     */
    public function handle(Package $package, array $targets): Package
    {
        $definitions = $this->normalise($targets);

        DB::transaction(function () use ($package, $definitions): void {
            $keys = $definitions->pluck('target_key')->all();

            $package->targets()->whereNotIn('target_key', $keys)->delete();

            foreach ($definitions as $definition) {
                $package->targets()->updateOrCreate(
                    ['target_key' => $definition['target_key']],
                    [
                        'label' => $definition['label'],
                        'target_value' => $definition['target_value'],
                        'sort_order' => $definition['sort_order'],
                    ],
                );
            }

            ProjectTargetOverride::query()
                ->whereIn('project_id', $package->projects()->withTrashed()->select('id'))
                ->whereNotIn('target_key', $keys)
                ->delete();
        });

        $package->unsetRelation('targets');

        return $package;
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     * @return Collection<int, array{target_key: string, label: string, target_value: int, sort_order: int}>
     */
    protected function normalise(array $targets): Collection
    {
        $definitions = collect(array_values($targets))->map(function (array $target, int $index): array {
            $key = trim((string) ($target['target_key'] ?? ''));
            $label = trim((string) ($target['label'] ?? ''));
            $value = $target['target_value'] ?? null;

            if (preg_match(PackageTarget::KEY_PATTERN, $key) !== 1 || strlen($key) > 64) {
                throw new InvalidArgumentException("Target key [{$key}] must be a lowercase slug (letters, numbers, underscores).");
            }

            if ($label === '' || strlen($label) > 100) {
                throw new InvalidArgumentException("Target [{$key}] needs a label of at most 100 characters.");
            }

            if (! is_numeric($value) || (int) $value < 0 || (int) $value != $value) {
                throw new InvalidArgumentException("Target [{$key}] value must be a non-negative integer.");
            }

            return [
                'target_key' => $key,
                'label' => $label,
                'target_value' => (int) $value,
                'sort_order' => is_numeric($target['sort_order'] ?? null) ? max(0, (int) $target['sort_order']) : $index,
            ];
        });

        $duplicates = $definitions->pluck('target_key')->duplicates();

        if ($duplicates->isNotEmpty()) {
            throw new InvalidArgumentException('Target keys must be unique within a package: '.$duplicates->implode(', ').'.');
        }

        return $definitions->values();
    }
}
